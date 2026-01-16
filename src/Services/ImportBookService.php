<?php

namespace Drupal\librarian\Services;

use Drupal\node\Entity\Node;

class ImportBookService
{

	protected $db = null;
	protected $a = "yes";

	public function __construct()
	{
		$this->db = \Drupal::database();
	}

	/**
	 * Given an ISBN, look up the information for the book, and add it to the table of known books
	 * Also download the cover image associated with the book and save it in the filesystem
	 * 
	 * @param string $isbn
	 * @return array
	 */
	public function getBookInfoFromISBN(string $isbn): array
	{
		$result = [];

		// $bookInfo = $this->lookupBookInfoGoogle($isbn);
		$bookInfo = $this-> getEmptyBookInfo($isbn);
		$bookInfo = $this->lookupBookInfoOpenLibrary($bookInfo);
		$bookInfo = $this->lookupBookInfoGoodReads($bookInfo);
		$result = $this->lookupBookInfoGoogle($bookInfo);

		// Sometimes the subtitle is the same as the description. If so, only use the subtitle
		if ($result['subtitle'] == $result['description']) {
			$result['description'] = '';
		}

		return $result;
	}

	private function getEmptyBookInfo(string $isbn): array
	{
		return [
			'isbn' => $isbn, 'title' => '', 'subtitle' => '', 'description' => '', 'publication_year' => 0, 
			'authors' => [], 'coverImage' => [], 'categories' => [],
		];
	}

	/**
	 * Go to an outside website to get book info if necessary
	 * But first check to see if the book already exists
	 * 
	 * @param array $bookInfo
	 * @return array|array{authors: mixed, categories: mixed, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, subtitle: mixed, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoGoogle(array $bookInfo): array
	{
		$result = [];

		$lookupURL = "https://www.googleapis.com/books/v1/volumes?q=isbn:" . $bookInfo['isbn'];
		$rawResponse = file_get_contents($lookupURL);
		$response = \Drupal\Component\Serialization\Json::decode($rawResponse);
		if ($response['totalItems'] > 0) {
			$book = $response['items'][0]['volumeInfo'];
			// For some reason, the cover is given with a HTTP URL

			$result = [
				'title' => $book['title'] ?: $bookInfo['title'],
				'language' => $book['language'] ?: $bookInfo['language'],
				'subtitle' => $book['subtitle'] ?? ($bookInfo['subtitle'] ?: ''),
				'description' => $book['description'] ?? ($bookInfo['description'] ?: ''),
				'publication_year' => $this->getPublicationYear($book['publishedDate']) ?: $bookInfo['publisheddate'],
				'authors' => $this->processAuthorNames($book['authors']) ?: $bookInfo['authors'],
				'categories' => $book['categories'],
				'coverImage' => $bookInfo['coverImage'],
			];
		}

		return $result;
	}

		/**
	 * Go to an outside website to get book info if necessary
	 * But first check to see if the book already exists
	 * 
	 * @param array $bookInfo
	 * @return array|array{authors: mixed, categories: mixed, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, subtitle: mixed, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoGoodReads(array $bookInfo): array
	{
		$result = [];

		$lookupURL = "https://bookcover.longitood.com/bookcover/" . $bookInfo['isbn'];
		$rawResponse = file_get_contents($lookupURL);
		if ($rawResponse) {
			$response = \Drupal\Component\Serialization\Json::decode($rawResponse);

			if (array_key_exists('url', $response)) {
				$bookInfo['coverImage'][] = $response['url'];
			}
		}

		return $bookInfo;
	}

	/**
	 * Do a best guess at which part of the name is the last name, and which is/are the first
	 * then put them "lastname, firstname", more useful for sorting
	 * 
	 * @param array $names
	 * @return array<array<string|null>|string>
	 */
	private function processAuthorNames(array $names): array
	{
		return array_map(function ($name) {
			$nameParts = explode(' ', $name);
			switch (count($nameParts)) {
				case 1:
					return $nameParts[0];
				case 2:
					return [$nameParts[1], $nameParts[0]];
				case 3:
					if (in_array(strtolower($nameParts[1]), ['de', 'du', 'von', 'di'])) {
						return [$nameParts[1] . ' ' . $nameParts[2], $nameParts[0]];
					}
				default:
					$lastname = array_pop($nameParts);
					$firstname = implode(' ', $nameParts);
					return [$lastname, $firstname];
			}

		}, $names);
	}

	/**
	 * Take an ISBN and look up its information in the Open Library
	 *
	 * @param array $bookInfo
	 * @return array|array{authors: array, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoOpenLibrary(array $bookInfo): array
	{
		$result = [];

		$lookupURL = "https://openlibrary.org/api/volumes/brief/isbn/" . $bookInfo['isbn'] . ".json";
		// dpr($lookupURL);
		$rawResponse = file_get_contents($lookupURL);
		$response = \Drupal\Component\Serialization\Json::decode($rawResponse);

		if (is_array($response) && count($response) > 0) {
			$book = array_pop($response['records']);
			// dpr($book);
			$details = $book['details']['details'];
			$authors = array_map(function ($author) {
				return $author['name'];
			}, $book['data']['authors']);

			$result = [
				'isbn' => $bookInfo['isbn'],
				'title' => $details['title'] ?: $bookInfo['title'],
				'subtitle' => $details['subtitle'] ?: $bookInfo['subtitle'],
				'description' => $details['description']['value'] ?: $bookInfo['subtitle'],
				'publication_year' => $this->getPublicationYear($details['publish_date'] ?: $bookInfo['publication_year']),
				'authors' => $this->processAuthorNames($authors) ?: $bookInfo['authors'],
				'coverImage' => $bookInfo['coverImage'],
			];
			$result['coverImage'][] = $book['data']['cover']['large'];
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
	private function getPublicationYear($pubDate)
	{
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
	 * Get a book from the database if it exists
	 * 
	 * @param string $isbn
	 * @return array
	 */
	private function getExistingBook(string $isbn): array
	{
		$result = [];
		// dpr($isbn);

		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'book')
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
	private function saveNewBookInfo(array $bookInfo): void
	{
		// dpr("SaVING");
		// dpr($bookInfo);
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
		$node->field_categories = $this->saveCategories($bookInfo['categories']);

		$node->save();
	}


	/**
	 * Take a URL and download the image into the local filesystem
	 * 
	 * @param string $isbn
	 * @param string $imageURL
	 */
	public function downloadImage(string $isbn, string $imageURL)
	{
		$imageTarget = "public://covers/$isbn.jpg";

		$imageData = file_get_contents($imageURL);
		$imageObject = \Drupal::service('file.repository')->writeData($imageData, $imageTarget);

		return $imageObject->id();
	}

	/**
	 * Save any categories that are new
	 * 
	 * @param array $categories
	 * @return array<int|mixed|string|null>
	 */
	private function saveCategories(array $categories): array
	{
		$result = [];

		foreach ($categories as $category) {
			$query = \Drupal::entityQuery('taxonomy_term')
				->accessCheck(true)
				->condition('vid', "book_categories")
				->condition('name', $category);
			$tids = $query->execute();

			if (count($tids) == 0) {
				// We couldn't find the term, so add it
				$new_term = \Drupal\taxonomy\Entity\Term::create([
					'vid' => 'book_categories',
					'name' => $category,
				]);

				$new_term->enforceIsNew();
				$new_term->save();
				$result[] = $new_term->id();
			} else {
				// Use the existing taxonomy term
				$result[] = array_pop($tids);
			}
		}

		return $result;
	}


}