<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class LibrarianController extends ControllerBase
{
	/**
	 * Receive an ISBN for a book, and return the book's information
	 * 
	 * @return JsonResponse
	 */
	public function lookupISBN(): JsonResponse {
		$param = \Drupal::request()->query->all();
		$isbn = $param['isbn'];

		$returnInfo = $this->getBookInfoFromISBN($isbn);

		return new JsonResponse([
			'data' => [
				'info' => $returnInfo,
			],
			'method' => 'GET',
			'status' => 200
		]);
	}

	public function getBorrowings(): JsonResponse {
		$currentUser = \Drupal::currentUser()->id();
		$returnInfo = $this->getUserBorrowings($currentUser);

		return new JsonResponse([
			'data' => [
				'info' => $returnInfo,
			],
			'method' => 'GET',
			'status' => 200
		]);
	}

	/**
	 * Get all the library information
	 * 
	 * @return JsonResponse
	 */
	public function getLibraryInfo() : JsonResponse {
		$result = [
			'books' => $this->getAllBookInfo(),
			'users' => $this->getAllPatronInfo(),
		];

		return new JsonResponse($result);
	}

	public function requestHolding() : JsonResponse {
		$result = [];

		$param = \Drupal::request()->query->all();
		if ($this->userCanRequestHolding($param['holding_id'] ?: 0)) {
			$result = ['status' => 'success', 'message' => 'Your request was accepted'];
		}

		return new JsonResponse($result);
	}

	private function getBookInfoFromISBN(string $isbn) :array  {
		$result = [];

		$bookInfo = $this->getExistingBook($isbn);

		if (!$bookInfo) {
			$bookInfo = $this->lookupBookInfoGoogle($isbn);
			$this->saveNewBookInfo($bookInfo);
			$bookInfo = $this->getExistingBook($isbn);
		}

		$return_fields = ['nid', 'title', 'body', 'field_isbn', 'field_publication_year'];


		foreach ($return_fields as $fieldName) {
			$result[$fieldName] = $bookInfo[$fieldName][0]['value'];
		}

		if ($bookInfo['field_cover_image']) {
			$file = \Drupal\file\Entity\File::load($bookInfo['field_cover_image'][0]['target_id']);
			$result['cover'] = \Drupal::service('file_url_generator')->generateString($file->getFileUri());
		} else {
			$result['cover'] = '';
		}

		$result['authors'] = $bookInfo['field_authors']
			? array_map(function ($author) {
				return $author['value'];
			}, $bookInfo['field_authors'])
			: [];

		return $result;
	}


	private function userCanRequestHolding(int $holdingID) : bool {
		$result = true;

		$holding = Node::load($holdingID);
		if (!$holding->field_available->value) {
			dpr("Holding is not available");
			return false;
		}
		if ($holding->field_owner->target_id == \Drupal::currentUser()->id()) {
			return false;
		}

		return $result;
	}

	/**
	 * Get information about books in this librarian community
	 * 
	 * @return array
	 */
	private function getAllBookInfo() : array {
		$result = [];

		$database = \Drupal::database();
		$query = $database->select('node', 'n');
		$query->join('node_field_data', 'nfd', 'n.nid=nfd.nid AND nfd.status=:status', [':status' => 1]);
		$query->join('node__body','nb','n.nid=nb.entity_id');
		$query->leftJoin('node__field_subtitle','nfs','n.nid=nfs.entity_id');
		$query->leftJoin('node__field_categories','nfc','n.nid=nfc.entity_id');
		$query->join('taxonomy_term_field_data', 'ttfd', 'nfc.field_categories_target_id=ttfd.tid');
		$query->leftJoin('node__field_cover_image', 'nfci', 'n.nid=nfci.entity_id');
		$query->condition('n.type', 'book');
		$query->orderBy('nfd.title');

		$query->addField('n', 'nid', 'book_id');
		$query->addField('nfd', 'title');
		$query->addField('nb','body_value','description');
		$query->addField('nfs','field_subtitle_value','subtitle');
		$query->addField('ttfd','name','category');
		$query->addField('nfci','field_cover_image_target_id','cover_fid');

		$books = $query->execute()->fetchAll();
		foreach ($books as $book) {
			if ($book->cover_fid) {
				$cover_image = \Drupal\file\Entity\File::load($book->cover_fid);
				$relative_url = $cover_image->getFileUri();
				$book->cover_url = \Drupal::service('file_url_generator')->generate($relative_url)->toString();
				unset($book->cover_fid);
			} else {
				$book->cover_url = '';
			}
			$result[$book->book_id] = $book;
			unset($book->book_id);
		}
		// dpr($query->__toString());
		$this->addAuthors($result);
		$this->addHoldingInfo($result);

		foreach ($result as $id => $book) {
			$book->book_id = $id;
		}

		return array_values($result);
	}

	/**
	 * Get the Holdings and add them to the list of Books
	 *
	 * @param array $books
	 * @return void
	 */
	private function addHoldingInfo(array $books) : void {
		$database = \Drupal::database();
		$query = $database->select('node', 'n');
		$query->condition('n.type', 'holding');
		$query->join('node_field_data', 'nfd', 'n.nid=nfd.nid AND nfd.status=:status', [':status' => 1]);
		$query->join('node__field_holding_book','nfhb','n.nid=nfhb.entity_id'); 
		$query->join('node__field_owner','nfo','nfhb.entity_id=nfo.entity_id');
		$query->join('node__field_available', 'nfa','nfhb.entity_id=nfa.entity_id');
		$query->addField('nfd', 'created', 'added_date');
		$query->addField('n', 'nid', 'holding_id');
		$query->addField('nfhb', 'field_holding_book_target_id', 'book_id');
		$query->addField('nfo', 'field_owner_target_id', 'owner_id');
		$query->addField('nfa', 'field_available_value', 'is_available');

		$holdings = $query->execute()->fetchAll();
		$this->addLoanInfo($holdings);
	
		foreach ($holdings as $holding) {
			if (array_key_exists($holding->book_id, $books)) {
				if (!isset($books[$holding->book_id]->holdings)) {
					$books[$holding->book_id]->holdings = [];
				}

				// Modify fields for formatting, etc.
				$holding->added_date = date('Y-m-d', $holding->added_date);
				$holding->is_owner = $this->userIsOwner($holding);
				$holding->is_available = $holding->is_available && !$holding->is_owner;

				$books[$holding->book_id]->holdings[$holding->holding_id] = $holding;
				unset($holding->holding_id);
				unset($holding->book_id);
			}
		}
		// dpr($query->__toString());
	}

	private function userIsOwner(Object $holding) : bool {
		return $holding->owner_id == \Drupal::currentUser()->id();
	}

	/**
	 * Get information about Patron users
	 * 
	 * @return array
	 */
	private function getAllPatronInfo() : array {
		$result = [];

		$database = \Drupal::database();
		$query = $database->select('users', 'u');
		$query->addField('u', 'uid');

		$query->join('user__field_first_name', 'uffn', 'u.uid=uffn.entity_id');
		$query->addField('uffn', 'field_first_name_value', 'firstname');

		$query->join('user__field_last_name', 'ufln', 'u.uid=ufln.entity_id');
		$query->addField('ufln', 'field_last_name_value', 'lastname');
		$users = $query->execute()->fetchAll();
		foreach ($users as $user) {
			$result[$user->uid] = $user;
			unset($user->uid);
		}

		return $result;
	}

	/**
	 * Get the Holdings and add them to the books
	 * 
	 * @param array $holdings
	 * @return void
	 */
	private function addLoanInfo(array $holdings) : void {
		$database = \Drupal::database();
		$query = $database->select('node', 'n');
		$query->condition('n.type', 'loan');
		$query->addField('n', 'nid', 'loan_id');

		$query->join('node__field_loan_status', 'nfls', 'n.nid=nfls.entity_id');
		$query->join('node__field_holding', 'nfh', 'n.nid=nfh.entity_id');
		$query->addField('nfls', 'field_loan_status_value', '');
		$query->addField('nfh', 'field_holding_target_id', 'holding_id');

		$loans = $query->execute()->fetchAll();
		// dpr($loans);
		// dpr($holdings);
		foreach ($loans as $loan) {
			foreach ($holdings as $holding) {
				// dpr($holding->holding_id . ' - ' . $loan->holding_id);
				if ($holding->holding_id == $loan->holding_id) {
					// dpr("Found holding");
					// dpr($holding);
					$holding->loans = [$loan->loan_id => $loan];
				} else {
					// dpr("Can't find " . $loan->holding_id);
					// dpr($holdings);
				}
			}

		}
	}

	/**
	 * Add Authors to the Books
	 * 
	 * @param mixed $books
	 * @return array
	 */
	private function addAuthors($books) : array {
		$database = \Drupal::database();
		$query = $database->select('node__field_authors', 'nfa');
		$query->addField('nfa', 'entity_id', 'book_id');
		$query->addField('nfa', 'field_authors_value', 'author_name');
		$query->orderBy('book_id');
		$query->orderBy('delta');
// dpr($books);
		$result = [];
		foreach ($query->execute()->fetchAll() as $author) {
			$bookID = $author->book_id;
			if (isset($books[$bookID]->authors)) {
				$books[$bookID]->authors[] = $author->author_name;
			} else {
				$books[$bookID]->authors = [$author->author_name];
			}
		}

		return $result;
	}

	/**
	 * Go to an outside website to get book info if necessary
	 * But first check to see if the book already exists
	 * 
	 * @param string $isbn
	 * @return array|array{authors: mixed, categories: mixed, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, subtitle: mixed, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoGoogle(string $isbn): array
	{
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

	/**
	 * Take an ISBN and look up its information in the Open Library
	 *
	 * @param string $isbn
	 * @return array|array{authors: array, coverImage: mixed, description: mixed, isbn: string, publication_year: mixed, raw_data: bool|string, title: mixed|array{error: string}}
	 */
	private function lookupBookInfoOpenLibrary(string $isbn): array
	{
		$result = [];

		$lookupURL = "https://openlibrary.org/api/volumes/brief/isbn/$isbn.json";
		// dpr($lookupURL);
		$rawResponse = file_get_contents($lookupURL);
		$response = \Drupal\Component\Serialization\Json::decode($rawResponse);

		if (count($response) > 0) {
			$book = array_pop($response['records']);
			// dpr($book);
			$details = $book['details']['details'];
			$authors = array_map(function ($author) {
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
	 * Get the book nodes owned by the given User
	 * 
	 * @param int $ownerID
	 * @return void
	 */
	private function getOwnerBooks(int $ownerID)
	{
		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'holding')
			->condition('deleted', '0');
		$nodeIDs = $query->execute();
		$nodeIDs = array_map(
			function (\Drupal\node\NodeInterface $node) {
				return $node->toArray();
			},
			Node::loadMultiple($nodeIDs)
		);

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
	 * Save any categories that are new
	 * 
	 * @param array $categories
	 * @return array<int|mixed|string|null>
	 */
	private function saveCategories(array $categories): array {
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

	/**
	 * Take a URL and download the image into the local filesystem
	 * 
	 * @param string $isbn
	 * @param string $imageURL
	 */
	private function downloadImage(string $isbn, string $imageURL)
	{
		$imageTarget = "public://covers/$isbn.jpg";

		$imageData = file_get_contents($imageURL);
		$imageObject = \Drupal::service('file.repository')->writeData($imageData, $imageTarget);

		return $imageObject->id();
	}

	private function getUserBorrowings(int $userId): array {
		$result = [];
		$userID = 3;

		$database = \Drupal::database();
		// Get Loan node
		$query = $database->select('node', 'loan');
		$query->condition('loan.type', 'loan');
		$query->join('node__field_holding', 'field_holding', 'loan.nid=field_holding.entity_id');
		$query->join('node__field_loan_status', 'loan_status', 'loan.nid=loan_status.entity_id');
		$query->join('node__field_requester', 'requester', 'loan.nid=requester.entity_id');
		$query->condition('field_requester_target_id', $userId);

		// Get Holding node
		$query->join('node', 'holding', 'field_holding.field_holding_target_id=holding.nid');
		// Get Book node
		$query->join('node__field_holding_book', 'holding_book', 'holding.nid=holding_book.entity_id');

		// Get Owner User
		$query->join('node__field_owner', 'owner', 'holding.nid=owner.entity_id');
		
		
		$query->addField('loan_status', 'field_loan_status_value', 'status');
		$query->addField('holding_book', 'field_holding_book_target_id', 'book_id');
dpr($query->__toString());
		$rows = $query->execute()->fetchAll();
dpr($rows);
		return $result;
	}
}