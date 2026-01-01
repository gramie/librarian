<?php

namespace Drupal\librarian\Services;

use Drupal\node\Entity\Node;
use ErrorException;

class LoanService
{
	const LOAN_STATUS_PENDING = 'Pending';
	const LOAN_STATUS_CANCELLED_BY_BORROWER = 'Cancelled by borrower';
	const LOAN_STATUS_CANCELLED_BY_LENDER = 'Cancelled by lender';
	const LOAN_STATUS_COMPLETE = 'Complete';
	const LOAN_STATUS_LENT_OUT = 'Lent out';
	const LOAN_STATUS_NOT_RETURNED = 'Not returned';


	protected $db = null;
	protected $currentUserRoles = [];

	public function __construct()
	{
		$this->db = \Drupal::database();
		$this->currentUserRoles = \Drupal::currentUser()->getRoles();
	}

	/**
	 * Get an array of all the books/holdings that this user has borrowed
	 * 
	 * @param int $userId
	 * @return array
	 */
	public function getUserBorrowings(int $userId): array
	{
		// Get Loan node
		$query = $this->db->select('node', 'loan');
		$query->condition('loan.type', 'loan');
		$query->join('node__field_holding', 'field_holding', 'loan.nid=field_holding.entity_id');
		$query->join('node__field_loan_status', 'loan_status', 'loan.nid=loan_status.entity_id');
		$query->join('node__field_borrower', 'borrower', 'loan.nid=borrower.entity_id');
		$query->leftJoin('node__field_loan_date', 'loan_date', 'loan.nid=loan_date.entity_id');
		$query->leftJoin('node__field_return_date', 'return_date', 'loan.nid=return_date.entity_id');
		$query->condition('field_borrower_target_id', $userId);

		// Get Holding node
		$query->join('node', 'holding', 'field_holding.field_holding_target_id=holding.nid');
		// Get Book node
		$query->join('node__field_holding_book', 'holding_book', 'holding.nid=holding_book.entity_id');
		$query->join('node_field_data', 'book_data', 'holding_book.field_holding_book_target_id = book_data.nid');

		// Get Owner User
		$query->join('node__field_owner', 'owner', 'holding.nid=owner.entity_id');
		$query->join('users', 'users', 'owner.field_owner_target_id = users.uid');
		$query->leftJoin('user__field_last_name', 'last_name', 'users.uid = last_name.entity_id');
		$query->leftJoin('user__field_first_name', 'first_name', 'users.uid = first_name.entity_id');

		// Select fields
		$query->addField('holding', 'nid', 'holding_id');
		$query->addField('loan_status', 'field_loan_status_value', 'status');
		$query->addField('loan_date', 'field_loan_date_value', 'loan_date_value');
		$query->addField('return_date', 'field_return_date_value', 'return_date_value');
		$query->addField('holding_book', 'field_holding_book_target_id', 'book_id');
		$query->addField('book_data', 'title');
		$query->addField('last_name', 'field_last_name_value', 'owner_last_name');
		$query->addField('first_name', 'field_first_name_value', 'owner_first_name');
		$rows = $query->execute()->fetchAll();
		foreach ($rows as $row) {
			$row->owner_name = $row->owner_first_name . ' ' . $row->owner_last_name;
		}

		$result = [
			'fields' => array_keys($query->getFields()),
			'visibleFields' => [
				'title' => 'Title',
				'owner_name' => 'Owner',
				'loan_date_value' => 'Loan date',
				'return_date_value' => 'Return date',
				'status' => 'Status'
			],
			'data' => array_map(function ($row) {
				return (array) $row;
			}, $rows),
		];

		return $result;
	}

	/**
	 * Get an array of all the books/holdings that this user has borrowed
	 * 
	 * @param int $userId
	 * @return array
	 */
	public function getUserLendings(int $userId): array
	{
		// Get Loan node
		$query = $this->db->select('node', 'loan');
		$query->condition('loan.type', 'loan');
		$query->join('node__field_holding', 'field_holding', 'loan.nid=field_holding.entity_id');
		$query->join('node__field_loan_status', 'loan_status', 'loan.nid=loan_status.entity_id');
		$query->join('node__field_borrower', 'borrower', 'loan.nid=borrower.entity_id');
		$query->leftJoin('node__field_loan_date', 'loan_date', 'loan.nid=loan_date.entity_id');
		$query->leftJoin('node__field_return_date', 'return_date', 'loan.nid=return_date.entity_id');

		// Get Holding node
		$query->join('node', 'holding', 'field_holding.field_holding_target_id=holding.nid');
		// Get Book node
		$query->join('node__field_holding_book', 'holding_book', 'holding.nid=holding_book.entity_id');
		$query->join('node_field_data', 'book_data', 'holding_book.field_holding_book_target_id = book_data.nid');

		// Get Owner User
		$query->join('node__field_owner', 'owner', 'holding.nid=owner.entity_id');
		$query->condition('owner.field_owner_target_id', $userId);

		// Get the Requester User
		$query->join('users', 'users', 'borrower.field_borrower_target_id = users.uid');
		$query->leftJoin('user__field_last_name', 'last_name', 'users.uid = last_name.entity_id');
		$query->leftJoin('user__field_first_name', 'first_name', 'users.uid = first_name.entity_id');

		// Select fields
		$query->addField('book_data', 'title');
		$query->addField('last_name', 'field_last_name_value', 'requester_last_name');
		$query->addField('first_name', 'field_first_name_value', 'requester_first_name');
		$query->addField('loan_date', 'field_loan_date_value', 'loan_date_value');
		$query->addField('return_date', 'field_return_date_value', 'return_date_value');
		$query->addField('loan', 'nid', 'loan_id');
		$query->addField('holding', 'nid', 'holding_id');
		$query->addField('loan_status', 'field_loan_status_value', 'status');
		$query->addField('holding_book', 'field_holding_book_target_id', 'book_id');
		$rows = $query->execute()->fetchAll();
		// dpr($query->__toString());
		foreach ($rows as $row) {
			$row->borrower_name = $row->requester_first_name . ' ' . $row->requester_last_name;
		}


		$result = [
			'fields' => array_keys($query->getFields()),
			'visibleFields' => [
				'title' => 'Title',
				'borrower_name' => 'Borrower',
				'loan_date_value' => 'Loan date',
				'return_date_value' => 'Return date',
				'status' => 'Status'
			],
			'data' => array_map(function ($row) {
				return (array) $row;
			}, $rows),
		];
		return $result;
	}

	/**
	 * Get the User IDs of all people who are in the given User's book circles
	 * 
	 * @param int $userID
	 * @return array
	 */
	public function getPeopleInUsersCircles(int $userID): array
	{
		$userIDs = [];

		// If the userID is not a valid user, return nothing
		$user = \Drupal\user\Entity\User::load($userID);
		if (!$user) {
			return [];
		}

		$circleIDs = array_map(function ($circle) {
			return $circle['target_id'];
		}, $user->field_circles->getValue());

		// It's possible that a user has no people in their circles
		if (count($circleIDs) > 0) {
			$database = \Drupal::database();
			$query = $database->select('user__field_circles', 'ufc');
			// Allow an administrator to see all users
			if (!in_array('administrator', $this->currentUserRoles)) {
				$query->condition('ufc.field_circles_target_id', $circleIDs, 'IN');
			}
			$query->addField('ufc', 'entity_id', 'userID');

			// People can be in multiple circles, so remove duplicates
			foreach ($query->execute()->fetchAll() as $row) {
				$userIDs[$row->userID] = true;
			}
		}

		return array_keys($userIDs);
	}

	private function changeLoanStatus(int $uid, int $loanID, string $action): bool
	{
		$loan = Node::load($loanID);
		$loanStatus = $loan->get('field_loan_status')->value;

		// dpr($loan);
		if ($loan) {
			$modified = false;
			switch ($action) {
				case 'cancel':
					if ($loanStatus === self::LOAN_STATUS_PENDING) {
						if ($uid == $loan->field_requester_target_id) {
							$loan->set('field_loan_status', self::LOAN_STATUS_CANCELLED_BY_BORROWER);
							$modified = true;
						} else {
							$holding = Node::load($loan->get('field_holding')->target_id);
							$owner = $holding->get('field_owner')->target_id;

							if ($holding && $uid == $owner) {
								$loan->set('field_loan_status', self::LOAN_STATUS_CANCELLED_BY_LENDER);
								$modified = true;
							}
						}
					} else {
						throw new ErrorException('Invalid action');
					}
					break;
				case 'lend':
					if ($loanStatus === self::LOAN_STATUS_PENDING) {
						$holding = Node::load($loan->get('field_holding')->target_id);
						$owner = $holding->get('field_owner')->target_id;

						if ($holding && $uid == $owner) {
							$loan->set('field_loan_status', self::LOAN_STATUS_LENT_OUT);
							$modified = true;
						}
					}

				case 'complete':
					if ($loanStatus === 'Lent out') {
						$holding = Node::load($loan->get('field_holding')->target_id);
						$owner = $holding->get('field_owner')->target_id;

						if ($holding && $uid == $owner) {
							$loan->set('field_loan_status', self::LOAN_STATUS_COMPLETE);
							$modified = true;
						}
					}
				case 'notreturned':
					if (in_array($loanStatus, ['Lent out', 'Complete'])) {
						$holding = Node::load($loan->get('field_holding')->target_id);
						$owner = $holding->get('field_owner')->target_id;

						if ($holding && $uid == $owner) {
							$loan->set('field_loan_status', self::LOAN_STATUS_COMPLETE);
							$modified = true;
						}
					}

			}

			if ($modified) {
				$loan->save();
				return false;
			}
		}

		return false;
	}

	private function isValidStateChange(string $fromState, string $toState): bool
	{
		$states = [
			'Pending' => ['Lent out', 'Cancelled by Borrower', 'Cancelled by owner'],
			'Lent out' => ['Complete'],
		];

		return in_array($toState, $states[$fromState]);
	}


	/**
	 * Determine whether the current user can request a Holding
	 * - No if the current user is the owner of the Holding
	 * - No if the Holding is marked "not available"
	 * 
	 * @param int $userID
	 * @param int $holdingID
	 * @return bool
	 */
	private function userCanRequestHolding(int $userID, int $holdingID): bool
	{
		$result = true;

		$holding = Node::load($holdingID);
		if (!$holding->field_available->value) {
			dpr("Holding is not available");
			return false;
		}
		if ($holding->field_owner->target_id == $userID) {
			return false;
		}

		return $result;
	}

	private function createLoan(int $userID, int $holding_id) : void {
		$node = Node::create([
			'type' => 'loan',
			'title' => "Loan - $holding_id to $userID"
		]);
		$node->field_holding = $holding_id;
		$node->field_loan_status = 'requested';
		$node->field_borrower = $userID;
		$node->field_loan_date = date('Y-m-d', time());

		$node->save();
	}


	public function getUserLibrary(int $userID) : array {
		$result = [];

		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'holding')
			->condition('field_borrower', $userID);
		$holdingIDs = $query->execute();
		// dpr($holdingIDs);

		if (count($holdingIDs) > 0) {
			// dpr($holdingIDs);
			$holdings = array_map(
				function (\Drupal\node\NodeInterface $node) {
					return $node->toArray();
				},
				Node::loadMultiple($holdingIDs)
			);

			// dpr($holdings);

			$loans = $this->getHoldingLoans($holdingIDs);
		}
		return $result;
	}

	protected function getHoldingLoans(array $holdingIDs) : array {
		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'loan')
			->condition('field_holding', $holdingIDs, 'IN')
			->condition('field_loan_status', ['Requested', 'Lent out', 'Lost'], 'IN');
		$nodeIDs = $query->execute();
		$nodes = array_map(
			function (\Drupal\node\NodeInterface $node) {
				return $node->toArray();
			},
			Node::loadMultiple($nodeIDs)
		);

		return $nodes;
	}

}