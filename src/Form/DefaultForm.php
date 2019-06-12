<?php

namespace Drupal\replaceentity\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\NodeType;

/**
 * Class DefaultForm.
 */
class DefaultForm extends FormBase
{


    /**
     * {@inheritdoc}
     */
    public function getFormId()
    {
        return 'mergeNodesForm';
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state)
    {
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
        $form['replacetype'] = [
            '#type' => 'select',
            '#title' => $this->t('AND select the type of tag to replace'),
            '#options' => [
                'drupal_entity' => $this->t('drupal-entity tags'),
               // <drupal-entity>
                'data_entity'=> $this->t('Anchor data-entity-substitution tags'),
                //<a data-entity-substitution..>
            ],
            '#size' => 2,
            '#weight' => '0',
        ];

        if ($form_state->isSubmitted()) {
            // get selected content type
            $content_type = $form_state->getValue('contenttype');
            $replacetype=$form_state->getValue('replacetype');
            if (empty($content_type) || empty($replacetype)) {
                drupal_set_message("No content type or replace type selected for mapping", 'warning');

            } else {

                $query = \Drupal::entityQuery('node');
                $query->condition('type', $content_type);
                $nids = $query->execute();
                $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

                // generate a mapping table of translations
                $rows = array();
                $connection = \Drupal::database();

                if($replacetype=='drupal-entity'){
                    foreach ($nodes as $node) {
                        $body = $node->get('body')->value;
                        if (preg_match("/drupal-entity/", $body, $match)) {
                            if (preg_match("/data-embed-button=\"media_browser\"/", $body, $match)) {

                                $text_chunks = preg_split("{<drupal-entity}", $body);
                                $num = sizeof($text_chunks) - 1;

                                for ($i = 1; $i <= $num; $i++) {

                                    $entity_block = preg_split('{</drupal-entity>}', $text_chunks[$i]);
                                    for ($e = 0; $e < sizeof($entity_block); $e++) {
                                        //Only edit space between <drupal-entity>...</drupal-entity>
                                        if ($e % 2 == 0) {

                                            $uuid = preg_split('{data-entity-uuid="}', $entity_block[$e]);
                                            $uuid = preg_split('{"}', $uuid[1]);

                                            $query = $connection->query
                                            ("SELECT c.uri 
                                              FROM media a, media_field_data b, file_managed c 
                                              WHERE a.mid=b.mid AND b.thumbnail__target_id=c.fid AND a.uuid='" . $uuid[0] . "'");
                                            $result = $query->fetchAll();

                                            foreach ($result as $res) {
                                                $uri = $res;
                                            }

                                            $alt = preg_split('{alt="}', $entity_block[$e]);
                                            $alt = preg_split('{"}', $alt[1]);
                                            $alt = $alt[0];

                                            $img_loc = preg_split('{public://}', $uri->uri);
                                            $img_loc = $img_loc[1];

                                            $to_be_replaced = "<drupal-entity" . $entity_block[$e] . "</drupal-entity>";

                                            $text = "<img alt=\"" . $alt . "\" src=\"/sites/default/files/" . $img_loc . "\"/>";

                                            $rows[] = array(
                                                $i,
                                                sizeof($text_chunks) - 1,
                                                $node->get('title')->value,
                                                $to_be_replaced,
                                                $text,
                                            );
                                        }
                                    }
                                }
                            }
                        }
                    }

                }else if ($replacetype=='data_entity'){

                    foreach ($nodes as $node) {
                        $body = $node->get('body')->value;
                        if (preg_match("/data-entity-substitution/", $body, $match)) {
                                $text_chunks = preg_split("{<a data-entity-substitution}", $body);
                                $num = sizeof($text_chunks) - 1;

                                for ($i = 1; $i <= $num; $i++) {

                                    $entity_block = preg_split('{>}', $text_chunks[$i]);

                                    $href = preg_split('{href="}', $entity_block[0]);
                                    $href = preg_split('{"}', $href[1]);
                                    $href=$href[0];

                                    if(preg_match("/node/", $href, $match)){
                                        //Get node id
                                        $prev_node = preg_split('{node/}', $href);
                                        $node_loc=$prev_node[1];
                                        foreach($nodes as $n){
                                            if($n->get('field_previous_id')->value==$node_loc){
                                                $node_loc_value=$n->get('nid')->value;
                                                $href=$prev_node[0].'node/'.$node_loc_value;
                                            }
                                        }

                                    }

                                    $to_be_replaced = "<a data-entity-substitution" . $entity_block[0] . ">";

                                    $text = "<a href=\"".$href."\">";

                                    $rows[] = array(
                                        $i,
                                        sizeof($text_chunks) - 1,
                                        $node->get('title')->value,
                                        $to_be_replaced,
                                        $text,
                                    );

                                }
                        }
                    }

                }

                // generate a table of mappings to render
                $form['mapping'] = [
                    '#type' => 'table',
                    '#header' => [$this->t('Num of'), $this->t('#'), $this->t('Title'), $this->t('Text to be replaced'), $this->t('text')],
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

    public function viewMappings(array &$form, FormStateInterface &$form_state)
    {
        $form_state->setRebuild();
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state)
    {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state)
    {
        $content_type = $form_state->getValue('contenttype');
        $replacetype=$form_state->getValue('replacetype');

        if (empty($content_type) || empty($replacetype)) {
            drupal_set_message('No content type selected', 'warning');
        } else {
            $query = \Drupal::entityQuery('node');
            $query->condition('type', $content_type);
            $nids = $query->execute();
            $nodes = \Drupal\node\Entity\Node::loadMultiple($nids);

            // generate a mapping table of translations
            $connection = \Drupal::database();
            if($replacetype=='drupal_entity'){
                foreach ($nodes as $node) {
                    $body = $node->get('body')->value;

                    if (preg_match("/drupal-entity/", $body, $match)) {

                        if (preg_match("/data-embed-button=\"media_browser\"/", $body, $match)) {
                            $text_chunks = preg_split("{<drupal-entity}", $body);
                            $num = sizeof($text_chunks) - 1;
                            $new_body = $text_chunks[0];

                            for ($i = 1; $i <= $num; $i++) {
                                $entity_block = preg_split('{</drupal-entity>}', $text_chunks[$i]);

                                for ($e = 0; $e < sizeof($entity_block); $e++) {
                                    if ($e % 2 == 0)
                                        $uuid = preg_split('{data-entity-uuid="}', $entity_block[$e]);
                                    $uuid = preg_split('{"}', $uuid[1]);

                                    $query = $connection->query
                                    ("SELECT c.uri 
                                          FROM media a, media_field_data b, file_managed c 
                                          WHERE a.mid=b.mid AND b.thumbnail__target_id=c.fid AND a.uuid='" . $uuid[0] . "'");
                                    $result = $query->fetchAll();

                                    foreach ($result as $res) {
                                        $uri = $res;
                                    }

                                    $alt = preg_split('{alt="}', $entity_block[$e]);
                                    $alt = preg_split('{"}', $alt[1]);
                                    $alt = $alt[0];

                                    $img_loc = preg_split('{public://}', $uri->uri);
                                    $img_loc = $img_loc[1];

                                    $text = "<img alt=\"" . $alt . "\" src=\"/sites/default/files/" . $img_loc . "\"/>";

                                    $new_body = $new_body . $text . $entity_block[$e + 1];

                                }
                            }
                        }

                        $node->body->value = $new_body;
                        $node->save();
                        drupal_set_message('Successfully Replaced: ' . $node->get('title')->value);
                    }

                }

            }elseif($replacetype=='data_entity'){
                foreach ($nodes as $node) {
                    $body = $node->get('body')->value;
                    if (preg_match("/data-entity-substitution/", $body, $match)) {
                        $text_chunks = preg_split("{<a data-entity-substitution}", $body);
                        $num = sizeof($text_chunks) - 1;
                        $new_body = $text_chunks[0];

                        for ($i = 1; $i <= $num; $i++) {

                            $entity_block = preg_split('{>}', $text_chunks[$i]);

                            $href = preg_split('{href="}', $entity_block[0]);
                            $href = preg_split('{"}', $href[1]);
                            $href=$href[0];

                            if(preg_match("/node/", $href, $match)){
                                //Get node id
                                $prev_node = preg_split('{node/}', $href);
                                $node_loc=$prev_node[1];
                                foreach($nodes as $n){
                                    if($n->get('field_previous_id')->value==$node_loc){
                                        $node_loc_value=$n->get('nid')->value;
                                        $href=$prev_node[0].'node/'.$node_loc_value;
                                    }
                                }

                            }
                            $text = "<a href=\"".$href."\">";

                            $new_body=$new_body . $text . $entity_block[1] .'>'.$entity_block[2];

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

