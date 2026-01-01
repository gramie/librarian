<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

class LibrarianController extends ControllerBase
{
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
		return $this->loanPageContents('borrowing');
	}

	public function lendingPage(): array
	{
		return $this->loanPageContents('lending');
	}

	/**
	 * Create the HTML for the loaning and borrowing pages
	 * 
	 * @param string $loanDirection
	 * @return array[]|array{#markup: string}
	 */
	protected function loanPageContents(string $loanDirection)
	{
		$tables = '<h2>Active</h2>'
			. '<table id="' . $loanDirection . '-table-active" class="display"><thead></thead><tbody></tbody></table>'

			. '<h2>Completed</h2>'
			. '<table id="' . $loanDirection . '-table-completed"><thead></thead><tbody></tbody></table>';
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


	/**
	 * Get all the library information
	 * 
	 * @return JsonResponse
	 */
	public function getLibraryInfo(): JsonResponse
	{
		$s = \Drupal::service('librarian_service.library');
		$result = [
			'books' => $s->getAllBookInfo(),
			'users' => $s->getAllPatronInfo(),
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
		$s = \Drupal::service('librarian_service.loan');
		if ($s->userCanRequestHolding($userID, $holding_id ?: 0)) {
			$s->createLoan($userID, $holding_id);
			$result = ['status' => 'success', 'message' => 'Your request was accepted'];
		}

		return new JsonResponse($result);
	}

	// End of API Endpoints
	/**
	 * Perform an action on a Loan
	 * 
	 * @return array
	 */
	public function loanAction(): array
	{
		$result = [];

		$param = \Drupal::request()->query->all();
		$action = $param['action'];
		$loanID = $param['loan_id'];
		$userID = \Drupal::currentUser()->id();

		if ($action && $loanID) {
			try {
				$s = \Drupal::service('librarian_service.loan');
				$s->changeLoanStatus($userID, $loanID, $action);
				$result['status'] = 'success';
			} catch (\Exception $e) {
				$result = [
					'status' => 'error',
					'message' => $e->getMessage(),
				];
			}
		} else {
			$result = [
				'status' => 'error',
				'message' => 'Invalid parameters',
			];
		}

		return $result;
	}
	
	public function getUserLibrary(): JsonResponse {
		$result = [];

		$userID = \Drupal::currentUser()->id();
		$s = \Drupal::service('librarian_service.library');
		$result = $s->getOwnerBooks($userID);

		return new JsonResponse($result);
	}


}