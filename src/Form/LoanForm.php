<?php
/**
 * @file
 * Contains \Drupal\librarian\Form\LoanForm
 */
namespace Drupal\librarian\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;

class LoanForm extends FormBase
{
    const NUMBER_OF_BOOKS = 5;

    public function getFormId()
    {
        return 'create_loan_form';
    }

    public function buildForm(array $form, FormStateInterface $form_state)
    {
        $form["patron"] = [
            '#type' => 'entity_autocomplete',
            '#title' => 'Patron name',
            '#target_type' => 'node',
            '#selection_settings' => [
                'target_bundles' => ['patron'],
            ]
        ];

        for ($i = 0; $i < self::NUMBER_OF_BOOKS; $i++) {
            $form["book-$i"] = [
                '#type' => 'entity_autocomplete',
                '#title' => ($i == 0) ? 'Books' : '',
                '#target_type' => 'node',
                '#selection_settings' => [
                    'target_bundles' => ['book'],
                ]
            ];
        }

        $form["loan_date"] = [
            '#type' => 'date',
            '#title' => $this->t('Loan date'),
            '#default_value' => date('Y-m-d', time()),
        ];

        $form["due_date"] = [
            '#type' => 'date',
            '#title' => $this->t('Due date'),
            '#default_value' => date('Y-m-d', strtotime('+2 weeks')),
        ];

        $form["patron"] = [
            '#type' => 'entity_autocomplete',
            '#title' => 'Patron name',
            '#target_type' => 'node',
            '#selection_settings' => [
                'target_bundles' => ['patron'],
            ]
        ];

        $form["notes"] = [
            '#type' => 'textarea',
            '#title' => $this->t('Notes'),
        ];

        $form['actions']['#type'] = 'actions';
        $form['actions']['submit'] = array(
            '#type' => 'submit',
            '#value' => $this->t('Loan books'),
            '#button_type' => 'primary',
        );

        return $form;
    }

    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        return parent::validateForm($form, $form_state);
    }

    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $values = $form_state->getValues();

        $loanData = [
            'patronID' => $values['patron'],
            'loanDate' => $values['loan_date'],
            'dueDate' => $values['due_date'],
            'notes' => $values['notes'],
        ];

        dpm($loanData);
        $booksLoaned = [];
        for ($i = 0; $i < self::NUMBER_OF_BOOKS; $i++) {
            $bookID = $values["book-$i"];
            if ($bookID && !in_array($bookID, $booksLoaned)) {
                $loanData['bookID'] = $bookID;
                $this->saveLoan($loanData);
                $booksLoaned[] = $bookID;
            }
        }
    }

    private function saveLoan(array $loanData)
    {
        $book = Node::load($loanData['bookID']);
        if ($book->get('field_is_available')->value) {
            // This book is available to loan out
            $patron = Node::load($loanData['patronID']);
            $loanNode = Node::create([
                'type' => 'loan',
                'title' => $book->title->value . ' => ' . $patron->title->value,
                'body' => [
                    'value' => $loanData['notes'],
                    'format' => 'basic_html'
                ],
            ]);
            $loanNode->field_book_borrowed->target_id = $loanData['bookID'];
            $loanNode->field_borrower->target_id = $loanData['patronID'];
            $loanNode->set('field_borrow_date', $loanData['loanDate']);
            $loanNode->set('field_due_date', $loanData['dueDate']);
            $loanNode->set('field_status', 'loaned');

            $loanNode->save();

            // When the book has been loaned out, it is no longer available to borrow
            $book->set('field_is_available', false);
            $book->save();
            \Drupal::messenger()->addMessage("Saved loan for " . $book->title->value);
        }
    }
}