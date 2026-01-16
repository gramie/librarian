<?php
namespace Drupal\librarian\Controller;

use Drupal\common_test\Render\MainContent\JsonRenderer;
use Drupal\Core\Controller\ControllerBase;
use Exception;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\node\Entity\Node;

class LibrarianController extends ControllerBase
{
	// API Endpoints 

	/**
	 * Receive an ISBN for a book, and return the book's information
	 * 
	 * @return JsonResponse
	 */
	public function lookupISBN(): JsonResponse
	{
		$returnInfo = ['error' => 'Book information not found'];

		$params = \Drupal::request()->query->all();
		$isbn = $params['isbn'];

		if ($isbn) {
			$libraryService = \Drupal::service('librarian_service.library');
			$book = $libraryService->getBookFromLibrary($isbn);
			if ($book) {
				$returnInfo['error'] = '"' . $book['title'][0]['value'] . '" is already in the library (Location: '
					. $book['field_location'][0]['value'] . ')';
			} else {
				$importService = \Drupal::service('librarian_service.importbook');
				$bookInfo = $importService->getBookInfoFromISBN($isbn);

				if ($bookInfo) {
					$returnInfo = $bookInfo;
				}
			}
		}

		return new JsonResponse($returnInfo);
	}

	public function createLoan(): JsonResponse
	{
		try {
			$params = \Drupal::request()->query->all();
			$bookID = $params['book_nid'];
			if (!$bookID) {
				throw new Exception('No Book ID');
			}
			$book = Node::load($bookID);
			if (!$book) {
				throw new Exception('Book not found');
			}
			$patronID = $params['patron_nid'];
			if (!$patronID) {
				throw new Exception('No Patron ID');
			}
			$patron = Node::load($patronID);
			if (!$patron) {
				throw new Exception('Patron not found');
			}

			if ($this->openLoanExists($bookID)) {
				throw new Exception('Book is already on loan.');
			} else {
				$node = Node::create([
					'type' => 'loan',
					'title' => $book->title->value . ': ' . $patron->title->value,
				]);
				$node->status = 1;
				$node->field_book_borrowed->target_id = $bookID;
				$node->field_borrower->target_id = $patronID;

				$node->save();
				$result = ['status' => 'OK', 'message', 'Loan created successfully'];
			}
		} catch (Exception $e) {
			$result = ['status' => 'ERROR', 'message' => "Error creating loan: " . $e->getMessage()];
		}

		return new JsonResponse($result);

	}

	private function openLoanExists(int $bookID): bool
	{
		$query = \Drupal::entityQuery('node')
			->condition('type', 'loan')
			->condition('field_book_borrowed', $bookID)
			->condition('field_status', 'Loaned')
			->accessCheck(TRUE);
		$result = $query->execute();

		return $result > 0;
	}

	public function updateLoan(): JsonResponse
	{
		$result = [];

		$params = \Drupal::request()->query->all();

		try {
			$libraryService = \Drupal::service('librarian_service.library');
			$loanID = $params['loan_nid'];
			if (!$loanID || $loanID <= 0) {
				throw new Exception('No valid Loan ID');
			}

			$newStatus = $params['new_status'];
			if (!in_array($newStatus, ['Returned', 'Lost'])) {
				throw new Exception('No valid status');
			}

			$libraryService->setLoanStatus($loanID, $newStatus);
		} catch (Exception $e) {
			$result = ['error' => $e->getMessage()];
		}

		return new JsonResponse($result);
	}

	/**
	 * Fix up book titles so that "A", "An" and "The" appear at the end
	 * and the titles can be sorted sensibly
	 * 
	 * @return JsonResponse
	 */
	public function fixBookTitles(): JsonResponse
	{
		$libraryService = \Drupal::service('librarian_service.library');

		$query = \Drupal::entityQuery('node')
			->condition('type', 'book')
			->accessCheck(TRUE);
		$nids = $query->execute();

		foreach (Node::loadMultiple($nids) as $book) {
			$title = $book->title->value;
			dpr("title = $title");
			if (strpos($title, 'A ') === 0 || strpos($title, 'An ') === 0 || strpos($title, 'The ') === 0 ) {
				$book->title->value = $libraryService->putTextInSortingFormat($title);
				$book->save();
				dpr($book->title->value);
			}
		}
		return new JsonResponse($result);
	}
}