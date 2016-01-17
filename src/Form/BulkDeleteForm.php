<?php

/**
 * @file
 * Contains Drupal\bulkdelete\Form\BulkDeleteForm.
 */

namespace Drupal\bulkdelete\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Database\Database;

/**
 * BackupDatabaseForm class.
 */
class BulkDeleteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'bulkdelete_form';
  }

  /**
   * {@inheritdoc}
   *
   * @todo, displays last backup timestamp
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $options = array();
		// Get list of content type.
    $types = node_type_get_types();
    ksort($types);
    foreach ($types as $key => $values) {
      $query = \Drupal::entityQuery('node')
      // Filter by content type.
      ->condition('type', $key)
      // Count.
      ->count();
      $count = $query->execute();
      if ($count > 0) {
        $options[$key] = $values->get('name') . " ($count)";
      }
    }
    if (empty($options)) {
		  $form['default_msg'] = array(
        '#type'   => 'item',
        '#markup' => t('Node not available.'),
      );
	  }
	  else {
      $form['types'] = array(
        '#type'        => 'checkboxes',
        '#title'       => $this->t('Content types for deletion'),
      '#options'     => $options,
    '#description' => t('All nodes of these types will be deleted using the batch API.'),
  );
  
  $form['actions']['#type'] = 'actions';
  $form['submit'] = array(
    '#type'  => 'submit',
    '#value' => $this->t('Delete'),
		'#button_type' => 'primary',
  );
  }
  return $form;		
 }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $types = array_filter($values['types']);
    if (count($types) > 0) {
      try {
		    foreach ($types as $bundle) {
				  $result = db_select('node');
          $query = $result->fields('node', array('nid'));
          $query = $result->condition('type', $bundle);
          $query = $result->execute()->fetchAll();
          $last_row = count($query);
					$operations = array();
					if (!empty($last_row)) {
						$message = t('All nodes of type @content mark for deletion', array('@content' => $bundle));
						\Drupal::logger('bulkdelete')->notice($message);
						// Create batch of 20 nodes.
						$count = 1;
						foreach ($query as $row) {
							$nids[] = $row->nid;
							if ($count % 20 === 0 || $count === $last_row) {
								$operations[] = array(array(get_class($this), 'processBatch'), array($nids));
								$nids = array();
							}
							++$count;
						}
						// Set up the Batch API
						$batch = array(
							'operations' => $operations,
							'finished' => array(get_class($this), 'bulkdelete_finishedBatch'),
							'title' => t('Node bulk delete'),
							'init_message' => t('Starting nodes deletion.'),
							'progress_message' => t('Completed @current step of @total.'),
							'error_message' => t('Bulk node deletion has encountered an error.'),
						);
						batch_set($batch);
					}
				}
			}
		  catch (Exception $e) {
        foreach ($e->getErrors() as $error_message) {
          drupal_set_message($error_message, 'error');
        }
	    }
    }
  }

  /**
   * Processes the bulk node deletion.
   *
   * @param array $nids
   *   Node nid.
   * @param array $context
   *   The batch context.
   */
  public static function processBatch($nids, &$context) {
    entity_delete_multiple('node', $nids);
  }

  /**
   * This function is called by the batch 'finished' parameter.
   * The cache must not be cleared as the last batch operation,
   * but after the batch is finished.
   * @param $success
   * @param $results
   * @param $operations
   */
  public static function bulkdelete_finishedBatch($success, $results, $operations) {
    drupal_flush_all_caches();
    $message = $success ? t('Bulkdelete performed successfully.') : t('Bulkdelete has not been finished successfully.');
    \Drupal::logger('bulkdelete')->notice($message);
    drupal_set_message($message);
  }
}
