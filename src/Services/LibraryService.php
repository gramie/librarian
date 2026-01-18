<?php

namespace Drupal\librarian\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;
use Exception;

class LibraryService
{
	/**
	 * Make changes when a book is saved
	 * 
	 * @param EntityInterface $entity
	 * @return void
	 */
	public function saveBook(EntityInterface $entity)
	{
		// If there is a remote URL for the book cover, download it to the local filesystem
		$isbn = $entity->get('field_isbn')->value;
		$remoteImageURL = $entity->get('field_remote_image_url')->value;

		if ($isbn && $remoteImageURL) {
			$s = \Drupal::service('librarian_service.importbook');

			$imageID = $s->downloadImage($isbn, $remoteImageURL);
			$entity->set('field_book_cover', [
				'target_id' => $imageID,
				'alt' => 'Book cover'
			]);
			// We don't need the remote URL any more
			$entity->set('field_remote_image_url', '');
		}

		// If the subtitle and description are the same (this sometimes happens)
		// drop the description
		if ($entity->get('field_subtitle')->value == $entity->get('body')->value) {
			$entity->set('body', '');
		}

		// If the title starts with "A", "An", "The", rearrange it
		// "A Simple Man" becomes "Simple Man, A"
		$entity->field_sorting_title->value = $this->putTextInSortingFormat($entity->title->value);
	}

	/**
	 * When a loan is saved, it affects the availability of the book for anyone else
	 * If the book is now lent, or is lost, it is unavailable
	 * If the book is returned, it is available
	 * 
	 * @param EntityInterface $entity
	 * @return void
	 */
	private function saveDuplicateLoan(EntityInterface $entity, int $bookID)
	{
		$node = Node::create([
			'type' => 'loan',
			'title' => $entity->title,
			'body' => [
				'value' => $bookInfo['description'],
				'format' => 'full_html'
			],
		]);

		$node->save();

	}

	/**
	 * If the book is in the library, return it
	 * 
	 * @param string $isbn
	 * @return array
	 */
	public function getBookFromLibrary(string $isbn): array {
		$query = \Drupal::entityQuery('node')
			->accessCheck(true)
			->condition('type', 'book')
			->condition('field_for_sale', true)
			->condition('field_isbn', $isbn);
		$result = $query->execute();
		if (count($result) > 0) {
			return Node::load(array_shift($result))->toArray();
		}
		return [];
	}

	/**
	 * Change the status of a loan
	 *
	 * @param integer $loanID
	 * @param string $newStatus
	 * @return void
	 */
	public function setLoanStatus(int $loanID, string $newStatus): void {
		$loan = Node::load($loanID);
		if (!$loan) {
			throw new Exception('Not a valid loan');
		}

		if (in_array($newStatus, ['Returned', 'Lost'])) {
			throw new Exception(('Not a valid status'));
		}

		$loanStatus = $loan->field_status->value;
		if ($loanStatus == 'Loaned') {
			$loan->field_status->value = $newStatus;
			$loan->save();

			// If a book has been returned, it is now available for others to borrow
			// If it was lost, it is no longer available, so don't change the availability
			if ($newStatus == 'Returned') {
				$bookID = $loan->field_book_borrowed->target_id;
				$book = Node::load($bookID);
				$book->field_is_available->value = true;
				$book->save();
			}
		}
	}

	/**
	 * Take a string and move "A ", "An", or "The " to the end
	 * e.g. "The Lion Sleeps Tonight" => "Lion Sleeps Tonight, The"
	 *
	 * @param string $input
	 * @return string
	 */
	public function putTextInSortingFormat(string $input): string {
		foreach($this->getTitleArticles() as $article) {
			dpr("Checking $input");
			if (strpos($input, $article) === 0) {
				$originalTitle = $input;
				$articleLen = strlen($article);
				$newTitle = substr($input, $articleLen) . ', ' . substr($article, 0, $articleLen -1);
				dpr("$originalTitle ==> $newTitle");
				return $newTitle;
			}
		}

		return $input;
	}

	/**
	 * Get an array of all the articles ("A", "An", "The", etc.) available
	 * @return string[]
	 */
	public function getTitleArticles(): array {
		return ['A ', 'An ', 'The ', 'Le ', 'La ', 'Les ', 'L\''];
	}

	public function titleStartsWithArticle(string $title) : bool
	{
		foreach ($this->getTitleArticles() as $article) {
			if (strpos($title, $article) === 0) {
				return true;
			}
		}

		return false;
	}

	public function titleEndsWithArticle(string $title): bool
	{
		foreach ($this->getTitleArticles() as $article) {
			$endArticle = ', ' . trim($article);
			$ending = substr(trim($title), -strlen($article) -1);
			if ($ending == $endArticle) {
				return true;
			}
		}

		return false;
	}
}