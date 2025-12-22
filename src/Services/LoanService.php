<?php

namespace Drupal\librarian\Services;

class LoanService
{

	protected $db = null;

	public function __construct()
	{
		$this->db = \Drupal::database();
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
		$result = [];

		$user = \Drupal\user\Entity\User::load($userID);
		if (!$user) {
			return $result;
		}

		$circleIDs = array_map(function ($circle) {
			return $circle['target_id'];
		}, $user->field_circles->getValue());

		if (count($circleIDs) > 0) {
			$database = \Drupal::database();
			$query = $database->select('user__field_circles', 'ufc');
			if (!\Drupal::currentUser()->hasRole('administrator')) {
				$query->condition('ufc.field_circles_target_id', $circleIDs, 'IN');
			}
			$query->addField('ufc', 'entity_id', 'userID');

			foreach ($query->execute()->fetchAll() as $row) {
				$result[$row->userID] = true;
			}
		}

		return array_keys($result);
	}


}