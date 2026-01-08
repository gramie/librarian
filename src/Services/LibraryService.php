<?php

namespace Drupal\librarian\Services;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\Entity\Node;

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

}