<?php
namespace Drupal\librarian\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

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
}