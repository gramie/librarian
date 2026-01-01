<?php

namespace Drupal\librarian\Services;

use Drupal\node\Entity\Node;
use ErrorException;

class LibraryService
{
    protected $database;

    public function __construct() {
        $this->database = \Drupal::database();
    }

	/**
	 * Get information about books in this librarian community
	 * 
	 * @return array
	 */
	public function getAllBookInfo(): array
	{
		$result = [];

		$s = \Drupal::service('librarian_service.loan');
		$currentUserID = \Drupal::currentUser()->id();
		$circleUsers = $s->getPeopleInUsersCircles($currentUserID);
		if (count($circleUsers) === 0) {
			// We need to have at least one user in the circle, even if the user has not joined a circle
			// So put the current user in
			$circleUsers = [$currentUserID];
		}

		$query = $this->database->select('node', 'n');
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
		if (!in_array('administrator', \Drupal::currentUser()->getRoles())) {
			$query->condition('field_owner_target_id', $circleUsers, 'IN');
		}
		$query->orderBy('nfd.title');

		$query->addField('n', 'nid', 'book_id');
		$query->addField('nfd', 'title');
		$query->addField('nb', 'body_value', 'description');
		$query->addField('nfs', 'field_subtitle_value', 'subtitle');
		$query->addField('ttfd', 'name', 'category');
		$query->addField('nfci', 'field_cover_image_target_id', 'cover_fid');

		$books = $this->getBookCovers($query->execute()->fetchAll());

		return array_values($books);
	}

	/**
	 * Get URLs for the covers of all the books
	 * 
	 * @param mixed $books
	 * @return array
	 */
	private function getBookCovers($books): array
	{
		foreach ($books as $book) {
			if ($book->cover_fid) {
				$cover_image = \Drupal\file\Entity\File::load($book->cover_fid);
				$relative_url = $cover_image->getFileUri();
				unset($book->cover_fid);
			} else {
				// If there is no book cover, substitute a default cover image
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

		return $books;
	}

	/**
	 * Get the Holdings and add them to the list of Books
	 *
	 * @param array $books
	 * @return array
	 */
	private function addHoldingInfo(array $books): array
	{
		$query = $this->database->select('node', 'n');
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

	/**
	 * Return "true" if the current user is the owner of a given Holding
	 * @param object $holding
	 * @return bool
	 */
	private function userIsOwner(object $holding): bool
	{
		return $holding->owner_id == \Drupal::currentUser()->id();
	}

	/**
	 * Get information about Patron users
	 * 
	 * @return array
	 */
	public function getAllPatronInfo(): array
	{
		$result = [];

		$s = \Drupal::service('librarian_service.loan');

		$query = $this->database->select('users', 'u');
		$query->addField('u', 'uid');

		$query->join('user__field_first_name', 'uffn', 'u.uid=uffn.entity_id');
		$query->addField('uffn', 'field_first_name_value', 'firstname');

		$query->join('user__field_last_name', 'ufln', 'u.uid=ufln.entity_id');
		$query->addField('ufln', 'field_last_name_value', 'lastname');

		// If user is NOT the administrator, limit users to people in their circles
		if (!in_array('administrator', \Drupal::currentUser()->getRoles())) {
			$circlePeople = $s->getPeopleInUsersCircles(\Drupal::currentUser()->id());
			$query->condition('u.uid', $circlePeople, 'IN');
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
		$query = $this->database->select('node', 'n');
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
		$query = $this->database->select('node__field_authors', 'nfa');
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
	public function getOwnerBooks(int $ownerID): array
	{
        $ownerID = 3;
		$query = $this->database->select('node', 'holdings');
        $query->join('node__field_holding_book', 'nfhb', 'holdings.nid=nfhb.entity_id');
        $query->join('node', 'books', 'books.nid=nfhb.field_holding_book_target_id');
        $query->join('node_field_data', 'nfd', 'books.nid=nfd.nid');
        $query->join('node__field_owner', 'nfo', 'holdings.nid=nfo.entity_id');
        $query->condition('holdings.type', 'holding');
        $query->condition('nfo.field_owner_target_id', $ownerID);
        $query->addField('holdings', 'nid', 'holding_id');
        $query->addField('books', 'nid', 'book_id');
        $query->addField('nfd', 'title');

		$holdings = $query->execute()->fetchAll();
        $holdings = $this->getHoldingStatuses($holdings);
        return $holdings;
	}

    protected function getHoldingStatuses(array $holdings): array {
        $keyedHoldings = [];
        foreach ($holdings as $holding) {
            $keyedHoldings[$holding->holding_id] = $holding;
        }
        $query = $this->database->select('node',  'loan');
		$query->join('node__field_loan_status', 'nfls', 'loan.nid=nfls.entity_id');
        $query->join('node__field_holding', 'nfh', 'loan.nid=nfh.entity_id');
        $query->condition('nfh.field_holding_target_id', array_keys($keyedHoldings), 'IN');
        $query->addField('nfls', 'field_loan_status_value', 'status');
        $query->addField('nfh', 'field_holding_target_id', 'holding_id');
		foreach ($query->execute()->fetchAll() as $row) {
            // dpr($row);
            $keyedHoldings[$row->holding_id]->status = $row->status;
        }
// dpr($keyedHoldings);
        return array_values($keyedHoldings);
    }

}