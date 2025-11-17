<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class LibrarianController extends ControllerBase {
	/**
	 * Receive an ISBN for a book, and return the book's information
	 * 
	 * @return JsonResponse
	 */
	public function lookupISBN() : JsonResponse {
		$param = \Drupal::request()->query->all();
		$isbn = $param['isbn'];

		$bookInfo = $this->getExistingBook($isbn);

		if (!$bookInfo) {
			$bookInfo = $this->lookupBookInfoGoogle($isbn);
			$this->saveNewBookInfo($bookInfo);
			$bookInfo = $this->getExistingBook($isbn);
		}
		
		$return_fields = ['nid', 'title', 'body', 'field_isbn', 'field_publication_year'];


		foreach ($return_fields as $fieldName) {
			$returnInfo[$fieldName] = $bookInfo[$fieldName][0]['value'];
		}

		if ($bookInfo['field_cover_image']) {
			$file = \Drupal\file\Entity\File::load($bookInfo['field_cover_image'][0]['target_id']);
  			$returnInfo['cover'] = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
		} else {
			$returnInfo['cover'] = '';
		}

		$returnInfo['authors'] = $bookInfo['field_authors']
			? array_map(function($author) {
				return $author['value'];
			}, $bookInfo['field_authors'])
			: [];

		return new JsonResponse([
			'data' => [
				'info' => $returnInfo,
			],
			'method' => 'GET',
			'status' => 200
		]);
	}

	/**
	 * Go to an outside website to get book info if necessary
	 * But first check to see if the book already exists
	 * 
	 * @param string $isbn
	 * @return array|array{authors: mixed, categories: mixed, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, subtitle: mixed, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoGoogle(string $isbn) : array {
		$result = [];

		$lookupURL = "https://www.googleapis.com/books/v1/volumes?q=isbn:$isbn";
		// dpr($lookupURL);
		$rawResponse = file_get_contents($lookupURL);
		$response = \Drupal\Component\Serialization\Json::decode($rawResponse);
		if ($response['totalItems'] > 0) {
			$book = $response['items'][0]['volumeInfo'];
			$result = [
				'isbn' => $isbn,
				'title' => $book['title'],
				'language' => $book['language'],
				'subtitle' => $book['subtitle'] ?? '',
				'description' => $book['description'] ?? '',
				'publication_year' => $this->getPublicationYear($book['publishedDate']),
				'authors' => $book['authors'],
				'coverImage' => $book['imageLinks']['thumbnail'],
				'categories' => $book['categories'],
				'raw_data' => $rawResponse,			
			];
		}

		if ($result == []) {
			$result = ['error' => 'Book not found'];
		}

		return $result;
	}

	private function lookupBookInfoOpenLibrary(string $isbn) : array {
		$result = [];

		$lookupURL = "https://openlibrary.org/api/volumes/brief/isbn/$isbn.json";
		// dpr($lookupURL);
		$rawResponse = file_get_contents($lookupURL);
		$response = \Drupal\Component\Serialization\Json::decode($rawResponse);

		if (count($response) > 0) {
			$book = array_pop($response['records']);
	// dpr($book);
			$details = $book['details']['details'];
			$authors = array_map(function($author) {
				return $author['name'];
			}, $book['data']['authors']);

			$result = [
				'isbn' => $isbn,
				'title' => $details['title'],
				'description' => $details['description']['value'] ?: $details['subtitle'] ?: '',
				'publication_year' => $this->getPublicationYear($details['publish_date']),
				'authors' => $authors,
				'coverImage' => $book['data']['cover']['large'],
				'raw_data' => $rawResponse,			
			];
		}

		if ($result == []) {
			$result = ['error' => 'Book not found'];
		}

		return $result;		
	}

	/**
	 * Convert a date string into a year that the book was published (date strings may be in different formats)
	 * @param mixed $pubDate
	 */
	private function getPublicationYear($pubDate) {
		// Date is like "1975-04-22"
		if (preg_match('/(\d\d\d\d)-\d\d-\d\d/', $pubDate, $matches)) {
			return $matches[1];
		}
		// Date is like "1975"
		if (preg_match('/(\d\d\d\d)$/', $pubDate, $matches)) {
			return $matches[1];
		}
		// Date may be some other string that can be parsed, like "April 22, 1975"
		return date('Y', strtotime($pubDate));
	}

	/**
	 * Get the book nodes owned by the given User
	 * 
	 * @param int $ownerID
	 * @return void
	 */
	private function getOwnerBooks(int $ownerID) {
		$entityTypeManager = $this->entityTypeManager();
		$nodeStorage = $entityTypeManager->getStorage('node');
		$query = $nodeStorage->getQuery()
			->accessCheck(true)
			->condition('type', 'holding')
			->condition('deleted', '0');
		$nodeIDs = $query->execute();
		$nodeIDs = array_map(function(\Drupal\node\NodeInterface $node) {
			return $node->toArray();
			},
			$nodeStorage->loadMultiple($nodeIDs)
		);
		
	}

	/**
	 * Get a book from the database if it exists
	 * 
	 * @param string $isbn
	 * @return array
	 */
	private function getExistingBook(string $isbn) : array {
		$result = [];
		// dpr($isbn);

		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type','book')
			->condition('field_isbn', $isbn);

		$book_ids = $query->execute();
		// dpr($book_ids);
		if (count($book_ids) > 0) {
			$bookID = array_pop($book_ids);
			$result = Node::load($bookID)->toArray();
		}

		// dpr($result);

		return $result;
	}

	/**
	 * Save book info into the database
	 * 
	 * @param array $bookInfo
	 * @return void
	 */
	private function saveNewBookInfo(array $bookInfo) : void {
		dpr("SaVING");
		dpr($bookInfo);
		$node = Node::create([
			'type' => 'book',
			'title' => $bookInfo['title'],
			'body' => [
				'value' => $bookInfo['description'],
				'format' => 'full_html'
			],
		]);
		$node->field_subtitle = $bookInfo['subtitle'];
		$node->field_isbn = $bookInfo['isbn'];
		$node->field_authors = $bookInfo['authors'];
		$node->field_raw_data = $bookInfo['raw_data'];
		$node->field_publication_year = $bookInfo['publication_year'];
		if ($bookInfo['coverImage']) {
			$node->set('field_cover_image', [
				'target_id' => $this->downloadImage($bookInfo['isbn'], $bookInfo['coverImage']),
				'alt' => 'Book cover'
			]);
		}
		$node->save();
	}

	/**
	 * Take a URL and download the image into the local filesystem
	 * 
	 * @param string $isbn
	 * @param string $imageURL
	 */
	private function downloadImage(string $isbn, string $imageURL) {
		$imageTarget = "public://covers/$isbn.jpg";

		$imageData = file_get_contents($imageURL);
		$imageObject = \Drupal::service('file.repository')->writeData($imageData, $imageTarget);

		return $imageObject->id();
	}
}