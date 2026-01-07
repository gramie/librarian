<?php
namespace Drupal\librarian\Controller;

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
		$params = \Drupal::request()->query->all();
		$isbn = $params['isbn'];

		$s = \Drupal::service('librarian_service.importbook');
		$returnInfo = $s->getBookInfoFromISBN($isbn);

		return new JsonResponse($returnInfo);
	}

	public function createLoan() : JsonResponse
	{
		try {
			$params = \Drupal::request()->query->all();
			$bookID = $params['book_nid'];
			if (!$bookID) {
				throw new Exception('No Book ID');
			}
			$book = \Drupal\node\Entity\Node::load($bookID);
			if (!$book) {
				throw new Exception('Book not found');
			}
			$patronID = $params['patron_nid'];
			if (!$patronID) {
				throw new Exception('No Patron ID');
			}
			$patron = \Drupal\node\Entity\Node::load($patronID);
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
		} catch (\Exception $e) {
			$result = ['status' => 'ERROR', 'message' => "Error creating loan: " . $e->getMessage()];
		}

		return new JsonResponse($result);

	}

	private function openLoanExists(int $bookID): bool {
		$query = \Drupal::entityQuery('node')
			->condition('type', 'loan')
			->condition('field_book_borrowed', $bookID)
			->condition('field_status', 'Loaned')
			->accessCheck(TRUE);
		$result = $query->execute();

		return $result > 0;
	}
}