<?php

namespace Drupal\librarian\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class ImportForm extends FormBase
{
	public function getFormId()
	{
		return 'librarian_import';
	}

	/**
	 * Create a form to allow users to enter ISBNs that will look up the book
	 * (if necessary) and then add the book to the current user's holdings
	 * 
	 * @param array $form
	 * @param \Drupal\Core\Form\FormStateInterface $form_state
	 * @return array
	 */
	public function buildForm(array $form, FormStateInterface $form_state)
	{
		$form['isbn'] = [
			'#type' => 'textfield',
			'#title' => 'ISBN',
			'#default_value' => '',
		];

		$form['scanner'] = [
			'#type' => 'inline_template',
			'#template' => '<div>
								<a class="button" id="startButton">Start</a>
								<a class="button" id="resetButton">Reset</a>
							</div>

							<div>
								<video id="video" width="300" height="200" style="border: 1px solid gray"></video>
							</div>

							<div id="sourceSelectPanel" style="display:none">
								<label for="sourceSelect">Change video source:</label>
								<select id="sourceSelect" style="max-width:400px">
								</select>
							</div>',
		];

		$form['book_list'] = [
			'#type' => 'inline_template',
			'#title' => 'Books to add',
			'#template' => '<table id="pending-book-list">
							<thead>
								<tr>
									<th>{{isbn}}</th>
									<th>{{title}}</th>
									<th>{{authors}}</th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>',
			'#context' => [
				'isbn' => t('ISBN'),
				'title' => t('Title'),
				'authors' => t('Authors'),
			],
		];

		$form['books_to_add'] = [
			'#type' => 'hidden',
			'#value' => '',
			'#id' => 'books-to-add',
		];

		$form['#attached']['library'][] = 'librarian/librarian';

		$form['actions'] = [
			'#type' => 'actions',
			'submit' => [
				'#type' => 'submit',
				'#value' => 'Submit',
			],
		];

		return $form;
	}

	/**
	 * Handle the submission of this form
	 * 
	 * @param array $form
	 * @param \Drupal\Core\Form\FormStateInterface $form_state
	 * @return void
	 */
	public function submitForm(array &$form, FormStateInterface $form_state)
	{
		$input = $form_state->getUserInput();
		$booksToAdd = explode(',', $input['books_to_add']);

		$bookNIDs = $this->getBookNIDs($booksToAdd);
		$uid = \Drupal::currentUser()->id();
		$currentHoldings = $this->getUserHoldings($uid);
		foreach ($booksToAdd as $isbn) {
			if (!in_array($isbn, $currentHoldings)) {
				$this->addHolding($uid, $bookNIDs[$isbn], $isbn);
			}
		}
		
		// Now add any holdings (copies of the submitted books) that this user doesn't have yet
	}

	/**
	 * Add a holding for this user
	 * 
	 * @param int $uid
	 * @param int $bookNID
	 * @param string $isbn
	 * @return void
	 */
	private function addHolding(int $uid, int $bookNID, string $isbn) : void {
		$node = Node::create([
			'type' => 'holding',
			'title' => $isbn,
		]);
		$node->field_available = 1;
		$node->field_holding_book->target_id = $bookNID;
		$node->field_owner = $uid;

		$node->save();

	}

	/**
	 * Get an array of all the nids of Books that this user owns
	 * 
	 * @param int $uid
	 * @return array
	 */
	private function getUserHoldings(int $uid): array {
		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'holding')
			->condition('field_owner', $uid);
		$nids = $query->execute();
		$nodes = Node::loadMultiple($nids);
		return array_map(function ($node) {
			return $node->nid;
		}, $nodes);
	}

	/**
	 * Get an array of the book nids corresponding to the supplied ISBNs
	 * 
	 * @param array $isbns
	 * @return array
	 */
	private function getBookNIDs(array $isbns) : array {
		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'book')
			->condition('field_isbn', $isbns, 'IN')
			->addTag('debug');
		$nodes = Node::loadMultiple($query->execute());

		$result = [];
		foreach($nodes as $node) {
			$n = $node->toArray();
			$result[$n['field_isbn'][0]['value']] = $n['nid'][0]['value'];
		}

		return $result;
	}
}