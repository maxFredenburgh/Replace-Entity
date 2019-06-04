<?php

namespace Drupal\replaceentity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

/**
 * Class DefaultForm.
 */
class DefaultForm extends FormBase {


  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'mergeNodesForm';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
      $all_content_types = NodeType::loadMultiple();
      $content_types = array();
      foreach ($all_content_types as $machine_name => $content_type) {
          $content_types[$content_type->id()] = $content_type->label();
      }
      ksort($content_types);

      // create a simple multi select list of content types
      $form['contenttype'] = [
          '#type' => 'select',
          '#title' => $this->t('Select a content type replace'),
          '#options' => $content_types,
          '#size' => count($content_types),
          '#weight' => '0',
      ];

      if ($form_state->isSubmitted()) {
          // get selected content type
          $content_type = $form_state->getValue('contenttype');
          if (empty($content_type)) {
              drupal_set_message("No content type selected for mapping", 'warning');
          }
          else {
              // fetch all nodes of content type
              $query = \Drupal::entityQuery('node');
              $query->condition('type', $content_type);
              $nids = $query->execute();
              $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

              // generate a mapping table of translations
              $rows = array();
              foreach ($nodes as $node) {
                  $body= $node->get('body')->value;
                  if(preg_match("/drupal-entity/", $body, $match)){
                      if(preg_match("/data-embed-button=\"media_browser\"/", $body, $match)){

                          $text_chunks = preg_split("{<drupal-entity}", $body);
                          $num= sizeof($text_chunks)-1;

                          for($i=1;$i<=$num;$i++){

                              $entity_block = preg_split('{</drupal-entity>}', $text_chunks[$i]);
                              for($e=0;$e<sizeof($entity_block);$e++){
                                  if($e%2==0){

                                      $uuid = preg_split('{data-entity-uuid="}', $entity_block[$e]);
                                      $uuid = preg_split('{"}', $uuid[1]);

                                      $to_be_replaced="<drupal-entity".$entity_block[$e]."</drupal-entity>";

                                      $text = "<drupal-entity data-embed-button=\"test\" data-entity-embed-display=\"view_mode:media.embedded\" 
                                      data-entity-type=\"media\" data-entity-uuid=\"$uuid[0]\"></drupal-entity>";

                                      $replace = $text;

                                      $rows[] = array(
                                          $i,
                                          sizeof($text_chunks)-1,
                                          $node->get('title')->value,
                                          $to_be_replaced,
                                          $replace,
                                      );
                                  }
                              }
                          }
                      }
                  }

              }

              // generate a table of mappings to render
              $form['mapping'] = [
                  '#type' => 'table',
                  '#header' => [$this->t('Num of'),$this->t('#'),$this->t('Title'), $this->t('Text to be replaced'), $this->t('text')],
                  '#rows' => $rows,
              ];

          }
      }
      $form['view_mapping'] = array(
          '#name' => 'view_mappings',
          '#type' => 'submit',
          '#value' => t('View Mapping'),
          '#submit' => array([$this, 'viewMappings']),
      );

      $form['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('Update Body'),
      ];


      return $form;
  }

  public function viewMappings(array &$form, FormStateInterface &$form_state) {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
      $content_type = $form_state->getValue('contenttype');
      if (empty($content_type)) {
          drupal_set_message('No content type selected', 'warning');
      } else {
          $query = \Drupal::entityQuery('node');
          $query->condition('type', $content_type);
          $nids = $query->execute();
          $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

          // generate a mapping table of translations

              foreach ($nodes as $node) {
                  $body = $node->get('body')->value;

                  if (preg_match("/drupal-entity/", $body, $match)) {

                      if(preg_match("/data-embed-button=\"media_browser\"/", $body, $match)){
                          $text_chunks = preg_split("{<drupal-entity}", $body);
                          $num= sizeof($text_chunks)-1;
                          $new_body=$text_chunks[0];

                          for($i=1;$i<=$num;$i++){
                              $entity_block = preg_split('{</drupal-entity>}', $text_chunks[$i]);

                              for($e=0;$e<sizeof($entity_block);$e++){

                                  if($e%2==0){
                                      $uuid = preg_split('{data-entity-uuid="}', $entity_block[$e]);
                                      $uuid = preg_split('{"}', $uuid[1]);

                                      $text = "<drupal-entity data-embed-button=\"test\" data-entity-embed-display=\"view_mode:media.embedded\" 
                                      data-entity-type=\"media\" data-entity-uuid=\"$uuid[0]\"></drupal-entity>";

                                      $new_body=$new_body.$text.$entity_block[$e+1];

                                  }
                              }
                          }

                          $node->body->value = $new_body;
                          $node->save();
                          drupal_set_message('Successfully Replaced: ' . $node->get('title')->value);
                      }

                  }

              }
      }
  }
}
