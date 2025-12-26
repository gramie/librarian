<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use ErrorException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class LibrarianController extends ControllerBase
{
	const LOAN_STATUS_PENDING = 'Pending';
	const LOAN_STATUS_CANCELLED_BY_BORROWER = 'Cancelled by borrower';
	const LOAN_STATUS_CANCELLED_BY_LENDER = 'Cancelled by lender';
	const LOAN_STATUS_COMPLETE = 'Complete';
	const LOAN_STATUS_LENT_OUT = 'Lent out';
	const LOAN_STATUS_NOT_RETURNED = 'Not returned';

	// API Endpoints 

	public function test(): JsonResponse
	{
		$result = [];
		$s = \Drupal::service('librarian_service.loan');

		$param = \Drupal::request()->query->all();
		$currentUser = \Drupal::currentUser()->id();
		$userID = array_key_exists('user', $param) ? $param['user'] : $currentUser;
		$result = $s->getPeopleInUsersCircles($userID);

		return new JsonResponse($result);
	}
	/**
	 * Receive an ISBN for a book, and return the book's information
	 * 
	 * @return JsonResponse
	 */
	public function lookupISBN(): JsonResponse
	{
		$param = \Drupal::request()->query->all();
		$isbn = $param['isbn'];

		$s = \Drupal::service('librarian_service.importbook');

		$returnInfo = $s->getBookInfoFromISBN($isbn);

		return new JsonResponse([
			'data' => [
				'info' => $returnInfo,
			],
			'method' => 'GET',
			'status' => 200
		]);
	}

	public function borrowingPage(): array
	{
		$tables = '<h2>Active</h2>'
			. '<table id="borrowing-table-active"><thead></thead><tbody></tbody></table>'
			. '<h2>Completed</h2>'
			. '<table id="borrowing-table-completed" class="display"><thead></thead><tbody></tbody></table>';
		$result = [
			'#markup' => $tables,
		];
		$result['#attached']['library'][] = 'librarian/library-loans';

		return $result;
	}

	/**
	 * Submit a request to get a list of all Holdings borrowed by the current user
	 * 
	 * @return array
	 */
	public function getBorrowings(): JsonResponse
	{
		$currentUser = \Drupal::currentUser()->id();
		$s = \Drupal::service('librarian_service.loan');
		$returnInfo = $s->getUserBorrowings($currentUser);

		// dpr($returnInfo);
		return new JsonResponse($returnInfo);
	}

	public function lendingPage(): array
	{
		$tables = '<h2>Active</h2>'
			. '<table id="lending-table-active" class="display"><thead></thead><tbody></tbody></table>'

			. '<h2>Completed</h2>'
			. '<table id="lending-table-completed"><thead></thead><tbody></tbody></table>';
		$result = [
			'#markup' => $tables,
		];
		$result['#attached']['library'][] = 'librarian/library-loans';

		return $result;
	}

	/**
	 * Submit a request to get a list of all Holdings lent by the current user
	 * 
	 * @return array
	 */
	public function getLendings(): JsonResponse
	{
		$currentUser = \Drupal::currentUser()->id();
		$returnInfo = \Drupal::service('librarian_service.loan')->getUserLendings($currentUser);

		// dpr($returnInfo);
		return new JsonResponse($returnInfo);
	}

	/**
	 * Get all the library information
	 * 
	 * @return JsonResponse
	 */
	public function getLibraryInfo(): JsonResponse
	{
		$result = [
			'books' => $this->getAllBookInfo(),
			'users' => $this->getAllPatronInfo(),
		];
		return new JsonResponse($result);
	}

	/**
	 * Submit a request to borrow a Holding
	 * 
	 * @return JsonResponse
	 */
	public function requestHolding(): JsonResponse
	{
		$result = [];

		$param = \Drupal::request()->query->all();
		$holding_id = $param['holding_id'];
		$userID = \Drupal::currentUser()->id();
		if ($this->userCanRequestHolding($userID, $holding_id ?: 0)) {
			$this->createLoan($userID, $holding_id);
			$result = ['status' => 'success', 'message' => 'Your request was accepted'];
		}

		return new JsonResponse($result);
	}

	// End of API Endpoints

	// Private functions

	/**
	 * Get information about books in this librarian community
	 * 
	 * @return array
	 */
	private function getAllBookInfo(): array
	{
		$result = [];

		$s = \Drupal::service('librarian_service.loan');
		$currentUserID = \Drupal::currentUser()->id();
		$circleUsers = $s->getPeopleInUsersCircles($currentUserID);
		if (count($circleUsers) === 0) {
			$circleUsers = [0];
		}

		$database = \Drupal::database();
		$query = $database->select('node', 'n');
		$query->join('node_field_data', 'nfd', 'n.nid=nfd.nid AND nfd.status=:status', [':status' => 1]);
		$query->join('node__body', 'nb', 'n.nid=nb.entity_id');
		$query->leftJoin('node__field_subtitle', 'nfs', 'n.nid=nfs.entity_id');
		$query->leftJoin('node__field_categories', 'nfc', 'n.nid=nfc.entity_id');
		$query->join('taxonomy_term_field_data', 'ttfd', 'nfc.field_categories_target_id=ttfd.tid');
		$query->leftJoin('node__field_cover_image', 'nfci', 'n.nid=nfci.entity_id');
		$query->join('node__field_holding_book', 'holdings', 'n.nid=holdings.field_holding_book_target_id');
		$query->join('node__field_owner', 'owner', 'holdings.entity_id=owner.entity_id');
		$query->condition('n.type', 'book');
		// Allow admins to see all books, not only ones in the User's Circles
		if (!\Drupal::currentUser()->hasRole('administrator')) {
			$query->condition('field_owner_target_id', $circleUsers, 'IN');
		}
		$query->orderBy('nfd.title');

		$query->addField('n', 'nid', 'book_id');
		$query->addField('nfd', 'title');
		$query->addField('nb', 'body_value', 'description');
		$query->addField('nfs', 'field_subtitle_value', 'subtitle');
		$query->addField('ttfd', 'name', 'category');
		$query->addField('nfci', 'field_cover_image_target_id', 'cover_fid');

		$books = $query->execute()->fetchAll();
		foreach ($books as $book) {
			if ($book->cover_fid) {
				$cover_image = \Drupal\file\Entity\File::load($book->cover_fid);
				$relative_url = $cover_image->getFileUri();
				unset($book->cover_fid);
			} else {
				$relative_url = 'public://covers/DefaultBookCover.png';
			}
			$book->cover_url = \Drupal::service('file_url_generator')->generate($relative_url)->toString();
			$book->holdings = [];
			$result[$book->book_id] = $book;
			unset($book->book_id);
		}
		// dpr($query->__toString());
		$this->addAuthors($result);
		$result = $this->addHoldingInfo($result);

		foreach ($result as $id => $book) {
			$book->book_id = $id;
		}

		return array_values($result);
	}

	/**
	 * Get the Holdings and add them to the list of Books
	 *
	 * @param array $books
	 * @return array
	 */
	private function addHoldingInfo(array $books): array
	{
		$database = \Drupal::database();
		$query = $database->select('node', 'n');
		$query->condition('n.type', 'holding');
		$query->join('node_field_data', 'nfd', 'n.nid=nfd.nid');
		$query->join('node__field_holding_book', 'nfhb', 'n.nid=nfhb.entity_id');
		$query->join('node__field_owner', 'nfo', 'nfhb.entity_id=nfo.entity_id');
		$query->join('node__field_available', 'nfa', 'nfhb.entity_id=nfa.entity_id');

		// Only get books that have available holdings
		$query->condition('nfd.status', 1);
		$query->condition('nfa.field_available_value', 1);

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
				$holding->is_available = !$holding->is_owner;
				$books[$holding->book_id]->holdings = [];
				$books[$holding->book_id]->holdings[$holding->holding_id] = $holding;
				unset($holding->holding_id);
				unset($holding->book_id);
			}
		}

		// Remove any books that have no available holdings
		foreach ($books as $idx => $book) {
			if (count($book->holdings) === 0) {
				unset($books[$idx]);
			}
		}

		return $books;

		// dpr($query->__toString());
	}

	private function userIsOwner(object $holding): bool
	{
		return $holding->owner_id == \Drupal::currentUser()->id();
	}

	/**
	 * Get information about Patron users
	 * 
	 * @return array
	 */
	private function getAllPatronInfo(): array
	{
		$result = [];

		$s = \Drupal::service('librarian_service.loan');
		
		$database = \Drupal::database();
		$query = $database->select('users', 'u');
		$query->addField('u', 'uid');

		$query->join('user__field_first_name', 'uffn', 'u.uid=uffn.entity_id');
		$query->addField('uffn', 'field_first_name_value', 'firstname');

		$query->join('user__field_last_name', 'ufln', 'u.uid=ufln.entity_id');
		$query->addField('ufln', 'field_last_name_value', 'lastname');

		// If user is NOT the administrator, limit users to people in their circles
    	if (!\Drupal::currentUser()->hasRole('administrator')) {
			$circlePeople = $s->getPeopleInUsersCircles(\Drupal::currentUser()->id());
			$query->condition('u.uid', $circlePeople , 'IN');
		}

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
	private function addLoanInfo(array $holdings): void
	{
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
	private function addAuthors($books): array
	{
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
			if (array_key_exists($bookID, $books)) {
				if (isset($books[$bookID]->authors)) {
					$books[$bookID]->authors[] = $author->author_name;
				} else {
					$books[$bookID]->authors = [$author->author_name];
				}
			}
		}

		return $result;
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

}