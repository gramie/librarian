<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class LibrarianController extends ControllerBase {
	public function lookupISBN() : JsonResponse {
		$param = \Drupal::request()->query->all();
		$isbn = $param['isbn'];

		$bookInfo = $this->getExistingBook($isbn);

		if (!$bookInfo) {
			$bookInfo = $this->lookupBookInfo($isbn);
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

	private function lookupBookInfo(string $isbn) : array {
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
		return date('Y', strtodate($pubDate));
	}

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

	private function saveNewBookInfo(array $bookInfo) : void {
		// dpr($bookInfo);
		$node = Node::create([
			'type' => 'book',
			'title' => $bookInfo['title'],
			'body' => [
				'value' => $bookInfo['description'],
				'format' => 'full_html'
			],
		]);
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

	private function downloadImage(string $isbn, string $imageURL) {
		$imageTarget = "public://covers/$isbn.jpg";

		$imageData = file_get_contents($imageURL);
		$imageObject = \Drupal::service('file.repository')->writeData($imageData, $imageTarget);

		return $imageObject->id();
	}
}