<?php

namespace Drupal\format_strawberryfield\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\InvokeCommand;
use Drupal\Core\Ajax\RemoveCommand;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\views\Views;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\format_strawberryfield\Entity\MetadataAPIConfigEntity;

/**
 * Form handler for metadataexpose_entity config entity add and edit.
 */
class MetadataAPIConfigEntityForm extends EntityForm {


  /**
   * MetadataExposeConfigEntityForm constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Initialize the form state and the entity before the first form build.
   */
  protected function init(FormStateInterface $form_state) {
    parent::init($form_state);
    if (!$this->entity->isNew()) {
      $config = $this->entity->getConfiguration();
      $form_state->setValue(
        'processor_wrapper_level_entity_id',
        $config['metadataWrapperDisplayentity'][0]
      );
      $form_state->setValue(
        'processor_item_level_entity_id',
        $config['metadataItemDisplayentity'][0]
      );
      $form_state->setValue(
        ['api_parameters_list', 'table-row'], $config['openAPI']
      );
      $form_state->setValue('views_source_id', $this->entity->getViewsSourceId());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);
    /* @var MetadataAPIConfigEntity $metadataconfig */
    $metadataconfig = $this->entity;

    $views = $this->getApplicationViewsAsOptions();

    // load via UUID Twig templates for wrapper and item
   /*  if ($this->getSetting('metadatadisplayentity_uuid')) {
      $entities = $this->entityTypeManager->getStorage('metadatadisplay_entity')->loadByProperties(['uuid' => $this->getSetting('metadatadisplayentity_uuid')]);
      $entity = reset($entities);
    } */
    // Set a bunch of form_state values to get around the fact
    // that elements with #limit_validation will not pass form values for other
    // elements and this will break the logic
    $api_source_configs = $form_state->getValue('api_source_configs') ?? $form_state->get('api_source_configs_temp');
    $form_state->setValue('api_source_configs', $api_source_configs);
    $form_state->set('api_source_configs_temp', $api_source_configs);
    $views_source_id = $form_state->getValue('views_source_id') ?? $form_state->get('views_source_id_tmp');
    $form_state->setValue('views_source_id', $views_source_id);
    $form_state->set('views_source_id_tmp', $views_source_id);

    $form = [
      'label' => [
        '#id' => 'label',
        '#type' => 'textfield',
        '#title' => $this->t('A label for this API Metadata endpoint'),
        '#default_value' => $metadataconfig->label(),
        '#required' => TRUE,
      ],
      'id' => [
        '#type' => 'machine_name',
        '#default_value' => $metadataconfig->id(),
        '#machine_name' => [
          'label' => '<br/>' . $this->t('Machine name used in the URL path to access your Metadata API'),
          'exists' => [$this, 'exist'],
          'source' => ['label'],
        ],
        '#disabled' => !$metadataconfig->isNew(),
        '#description' => $this->t('Machine name used in the URL path to access your Metadata API. E.g if "oaipmh" is chosen as value, access URL will be in the form of "/ap/api/<b>oaipmh</b>"'),
      ],
      'views_source_id' => [
        '#type' => 'select',
        '#title' => $this->t('Views source'),
        '#description' => $this->t('The Views that will provide data for this API'),
        '#options' => $views,
        '#default_value' => $form_state->getValue('views_source_id') ?? NULL,
        '#required' => TRUE,
        '#ajax' => [
          'trigger_as' => ['name' => 'metadata_api_configure'],
          'callback' => '::buildAjaxAPIConfigForm',
          'wrapper' => 'api-source-config-form',
          'method' => 'replace',
          'effect' => 'fade',
        ],
      ],
      'api_parameter_configs' => [
        '#type' => 'container',
        '#tree' => TRUE,
        '#attributes' => [
          'id' => 'api-parameters-config',
        ],
      ],
      'add_fieldset' => [
        '#type' => 'fieldset',
        '#attributes' => [
          'id' => 'api-add-parameter-config-button-wrapper',
          'class' => isset($form_state->getTriggeringElement()['#name']) && $form_state->getTriggeringElement()['#name'] == 'metadata_add_parameter' ? ['js-hide'] : [],
        ],
        'add_more' => [
          '#type' => 'submit',
          '#name' => 'metadata_add_parameter',
          '#value' => t('Add Parameter to this API'),
          '#attributes' => [
            'id' => 'api-add-parameter-config-button'
          ],
          '#limit_validation_errors' => [['views_source_id']],
          '#submit' => ['::submitAjaxAPIConfigFormAdd'],
          '#ajax' => [
            'trigger_as' => ['name' => 'metadata_api_configure'],
            'callback' => '::buildAjaxAPIParameterConfigForm',
            'wrapper' => 'api-parameters-config',
          ],
        ],
      ],
      'api_source_configs' => [
        '#type' => 'fieldset',
        '#attributes' => [
          'id' => 'api-source-config-form',
        ],
        '#title' => $this->t('Exposed filters and arguments for the selected source View'),
        '#description' => $this->t('These can be mapped to receive -transformed- values from any configured API parameter'),
        '#tree' => TRUE
      ],
      'api_parameters_list' => [
        '#type' => 'fieldset',
        '#attributes' => [
          'id' => 'api-parameters-list-form',
        ],
        '#tree' => TRUE,
      ],
      'processor_wrapper_level_entity_id' => [
        '#type' => 'sbf_entity_autocomplete_uuid',
        '#title' => $this->t('The Metadata display Entity (Twig) to be used to generate data for the API wrapper response.'),
        '#target_type' => 'metadatadisplay_entity',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#default_value' => (!$metadataconfig->isNew()) ? $form_state->getValue('processor_wrapper_level_entity_id') : NULL,
      ],
      'processor_item_level_entity_id' => [
        '#type' => 'sbf_entity_autocomplete_uuid',
        '#title' => $this->t('The Metadata display Entity (Twig) to be used to generate data at this endpoint.'),
        '#target_type' => 'metadatadisplay_entity',
        '#selection_handler' => 'default:metadatadisplay',
        '#validate_reference' => TRUE,
        '#required' => TRUE,
        '#default_value' => (!$metadataconfig->isNew()) ? $form_state->getValue('processor_item_level_entity_id') : NULL,
      ],
      'active' => [
        '#type' => 'checkbox',
        '#title' => $this->t('Is this Metadata API active?'),
        '#return_value' => TRUE,
        '#default_value' => ($metadataconfig->isNew()) ? TRUE : $metadataconfig->isActive()
      ],
      'metadata_api_configure_button' => [
        '#type' => 'submit',
        '#name' => 'metadata_api_configure',
        '#value' => $this->t('Configure Metadata API'),
        '#limit_validation_errors' => [['views_source_id']],
        '#submit' => ['::submitAjaxAPIConfigForm'],
        '#ajax' => [
          'callback' => '::buildAjaxAPIConfigForm',
          'wrapper' => 'api-source-config-form',
        ],
        '#attributes' => ['class' => ['js-hide']],
      ]
    ];
    // We need the views arguments here so we run this first.
    $this->buildAPIConfigForm($form, $form_state);
    $this->buildCurrentParametersConfigForm($form, $form_state);
    $this->buildParameterConfigForm($form, $form_state);

    return $form;
  }

  /**
   * Handles changes to the selected View Source
   */
  public function buildAjaxAPIConfigForm(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(
      new ReplaceCommand("#api-parameters-list-form", $form['api_parameters_list'])
    );
    $response->addCommand(
      new ReplaceCommand("#api-source-config-form", $form['api_source_configs'])
    );

    return $response;

    //return $form['api_source_configs'];
  }

  /**
   * Handles Parameter config display
   */
  public function buildAjaxAPIParameterConfigForm(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(
      new ReplaceCommand("#api-parameters-config", $form['api_parameter_configs'])
    );
    $response->addCommand(new InvokeCommand('#api-add-parameter-config-button', 'toggleClass', ['js-hide']));
    return $response;
  }

  /**
   * Handles updates on the parameters Config form.
   */
  public function buildAjaxAPIParameterListConfigForm(array $form, FormStateInterface $form_state) {
    $response = new AjaxResponse();
    $response->addCommand(
      new ReplaceCommand("#api-parameters-list-form", $form['api_parameters_list'])
    );
    $response->addCommand(
      new RemoveCommand(
        "#api-parameters-config-internal"
        )
    );
   $response->addCommand(new InvokeCommand('#api-add-parameter-config-button-wrapper', 'removeClass', ['js-hide']));
    return $response;
  }


  public function deleteoneCallback(array $form, FormStateInterface $form_state) {
    return $form['api_parameters_list']['table-row'];
  }



  /**
   * Handles form submissions for the API source subform.
   */
  public function submitAjaxAPIConfigForm($form, FormStateInterface $form_state) {
    $userinput = $form_state->getUserInput();
    // Only way to get that tabble drag form to rebuild completely
    // If not we get always the same table back with the last element
    // removed.
    unset($userinput['api_parameters_list']['table-row']);
    $form_state->setUserInput($userinput);
    $form_state->setRebuild();
  }

  /**
   * Handles form submissions for the API source subform.
   */
  public function submitAjaxAPIConfigFormAdd($form, FormStateInterface $form_state) {
    $form_state->setRebuild();
  }


  /**
   * Handles form submissions for the API source subform.
   */
  public function editParameter($form, FormStateInterface $form_state) {
    $triggering = $form_state->getTriggeringElement();
    if (isset($triggering['#rowtoedit'])) {

      $parameters = $form_state->get('parameters') ? $form_state->get(
        'parameters'
      ) : [];
      $form_state->setValue(['api_parameter_configs','params'],  $parameters[$triggering['#rowtoedit']]);
      $this->messenger()->addWarning('You are editing @param_name', ['@param_name' => $parameters[$triggering['#rowtoedit']]]);
    }
    $form_state->setRebuild();
  }

  /**
   * Submit handler for the "deleteone" button.
   *
   * Adds Key and View Mode to the Table Drag  Table.
   */
  public function deletePair(array &$form, FormStateInterface $form_state) {

    $triggering = $form_state->getTriggeringElement();
    if (isset($triggering['#rowtodelete'])) {
      $parameters = $form_state->get('parameters') ? $form_state->get(
        'parameters'
      ) : [];
      unset($parameters[$triggering['#rowtodelete']]);
      $form_state->set('parameters',$parameters);
      $this->messenger()->addWarning('You have unsaved changes.');
      $userinput = $form_state->getUserInput();
      // Only way to get that tabble drag form to rebuild completely
      // If not we get always the same table back with the last element
      // removed.
      unset($userinput['api_parameters_list']['table-row']);
      $form_state->setUserInput($userinput);
    }
    $form_state->setRebuild();
  }


  /**
   * Submit handler for the "addmore" button.
   *
   * Adds Key and View Mode to the Table Drag  Table.
   */
  public function submitAjaxAddParameter(array &$form, FormStateInterface $form_state) {

    $parameters = $form_state->get('parameters') ? $form_state->get(
      'parameters'
    ) : [];
    //@TODO we need to be sure $parameters is unique and keyed by the name
    $name = $form_state->getValue(['api_parameter_configs','params','name']);
    if ($name) {
      $parameter_clean = $form_state->getValue(['api_parameter_configs','params']);
      unset($parameter_clean['metadata_api_configure_button']);
      $parameters[$name] = $parameter_clean;
      $this->messenger()->addWarning('You have unsaved changes.');
      $form_state->set('parameters', $parameters);
    }
    // Re set since they might have get lost during the Ajax/Limited validation
    // processing.
    $api_source_configs = $form_state->get('api_source_configs_temp') ?? $form_state->getValue('api_source_configs');
    $form_state->setValue('api_source_configs', $api_source_configs);
    $views_source_id = $form_state->get('views_source_id_tmp') ?? $form_state->getValue('views_source_id');
    $form_state->setValue('views_source_id', $views_source_id);
    $form_state->setRebuild();
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Remove button and internal Form API values from submitted values.
    $form_state->cleanValues();
    $new_form_state = clone $form_state;
    // no need to unset original values, they won't match Entities properties
    $config['openAPI'] =  $new_form_state->getValue(['api_parameters_list','table-row']);
    // Return this to expanded form to make editing easier but also to conform to
    // Drupal schema and clean up a little bit?
    foreach ($config['openAPI'] as &$openAPIparameter) {
      $openAPIparameter['param'] = json_decode($openAPIparameter['param'], TRUE);
      unset($openAPIparameter['actions']);
    }
    $config['metadataWrapperDisplayentity'][] = $form_state->getValue('processor_wrapper_level_entity_id', NULL);
    $config['metadataItemDisplayentity'][] = $form_state->getValue('processor_item_level_entity_id', NULL);
    $new_form_state->setValue('configuration', $config);
    $this->entity = $this->buildEntity($form, $new_form_state);
  }

  /**
   * Builds the configuration form for the selected Views Source.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  public function buildAPIConfigForm(array &$form, FormStateInterface $form_state) {
    $selected_view = $form_state->getValue('views_source_id');
    if ($selected_view) {
      // We need to reset this bc on every View change this might be new/non existing
      $views_argument_options  = [];

      [$view_id, $display_id] = explode(':', $selected_view);
      /** @var \Drupal\views\Entity\View $view */
      $view = $this->entityTypeManager->getStorage('view')->load($view_id);
      $display = $view->getDisplay($display_id);
      $executable = $view->getExecutable();
      $executable->setDisplay($display_id);
  // also check $executable->display_handler->options['arguments']
      foreach ($executable->display_handler->options['filters'] as $filter) {
        if ($filter['exposed'] == TRUE) {
          $form['api_source_configs'][$filter['id']]['#type'] = 'textfield';
          $form['api_source_configs'][$filter['id']]['#attributes']
            = ['class' => ['format-strawberryfield-api-source-config-wrapper']];
          $form['api_source_configs'][$filter['id']]['#title'] = $this->t(
            'Exposed Filter id: @id for field @field found in <em>@table</em> @admin_label', [
              '@id' => $filter['id'],
              '@field' => $filter['field'],
              '@table' => $filter['table'],
              '@admin_label' => !empty($filter['expose']['label']) ? '('. $filter['expose']['label'] .')' : '' ,
            ]
          );
          $views_argument_options[$filter['id']] = $filter['expose']['label'] ?? $filter['id'];
        }
      }
      foreach ($executable->display_handler->options['arguments'] as $filter) {
          $form['api_source_configs'][$filter['id']]['#type'] = 'textfield';
          $form['api_source_configs'][$filter['id']]['#attributes']
            = ['class' => ['format-strawberryfield-api-source-config-wrapper']];
          $form['api_source_configs'][$filter['id']]['#title'] = $this->t(
            'Argument id: @id for field @field found in <em>@table</em> @admin_label', [
              '@id' => $filter['id'],
              '@field' => $filter['field'],
              '@table' => $filter['table'],
              '@admin_label' => !empty($filter['expose']['label']) ? '('. $filter['expose']['label'] .')' : '' ,
            ]
          );
        $views_argument_options[$filter['id']] = $filter['expose']['label'] ?? $filter['id'];
      }
      $form_state->set('views_argument_options', $views_argument_options);
    }
  }


  /**
   * Builds the configuration form for a single Parameter.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  public function buildParameterConfigForm(array &$form, FormStateInterface $form_state) {
    $selected_view = $form_state->getValue('views_source_id');
    // As defined in https://github.com/OAI/OpenAPI-Specification/blob/3.0.2/versions/3.0.2.md#parameter-object
    $hide = TRUE;
    if ($form_state->getTriggeringElement()
      && ($form_state->getTriggeringElement()['#parents'][0] == 'add_more' || isset($form_state->getTriggeringElement()['#rowtoedit']))
    ) {
      $hide = FALSE;
    }
    if (!$hide) {
      $form['api_parameter_configs']['params'] = [
        '#type'       => 'fieldset',
        '#title'      => 'Configure API parameter',
        '#tree'       => TRUE,
        '#attributes' => [
          'id' => 'api-parameters-config-internal'
        ]
      ];
      $form['api_parameter_configs']['params']['name'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Name'),
        '#description'   => $this->t(
          'The name of the parameter. Parameter names are case sensitive.'
        ),
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','name']) ?? NULL,
        '#required'      => TRUE,
        '#disabled' => isset($form_state->getTriggeringElement()['#rowtoedit']),
      ];
      $form['api_parameter_configs']['params']['in'] = [
        '#type'          => 'select',
        '#title'         => $this->t('In'),
        '#description'   => $this->t('The location of the parameter'),
        '#options'       => [
          'query'  => 'query',
          'header' => 'header',
          'path'   => 'path',
          'cookie' => 'cookie'
        ],
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','in']) ?? 'query',
        '#required'      => TRUE,
      ];

      $form['api_parameter_configs']['params']['description'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Description'),
        '#description'   => $this->t(
          'A brief description of the parameter. This could contain examples of use.'
        ),
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','description']) ?? NULL,
        '#required'      => FALSE,
      ];
      $form['api_parameter_configs']['params']['required'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Required'),
        '#description'   => $this->t(
          'Determines whether this parameter is mandatory. If "in" is "path" this will be checked automatically.'
        ),
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','required']) ?? FALSE,
        '#required'      => FALSE,
      ];
      $form['api_parameter_configs']['params']['deprecated'] = [
        '#type'          => 'checkbox',
        '#title'         => $this->t('Deprecate'),
        '#description'   => $this->t(
          'Specifies that a parameter is deprecated and SHOULD be transitioned out of usage.'
        ),
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','deprecated']) ?? FALSE,
        '#required'      => FALSE,
      ];
      // https://github.com/OAI/OpenAPI-Specification/blob/3.0.2/versions/3.0.2.md#style-values
      $form['api_parameter_configs']['params']['style'] = [
        '#type'          => 'select',
        '#title'         => $this->t('Style'),
        '#description'   => $this->t(
          'Describes how the parameter value will be serialized depending on the type of the parameter value.'
        ),
        '#options'       => [
          'form'           => 'form',
          'simple'         => 'simple',
          'label'          => 'label',
          'matrix'         => 'matrix',
          'spaceDelimited' => 'spaceDelimited',
          'pipeDelimited'  => 'pipeDelimited',
          'deepObject'     => 'deepObject',
        ],
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','style']) ?? 'form',
        '#required'      => TRUE,
      ];
      $form['api_parameter_configs']['params']['schema'] = [
        '#type' => 'fieldset',
        '#tree' => TRUE,
      ];
      // All of these will be readOnly: true
      $form['api_parameter_configs']['params']['schema']['type'] = [
        '#type'          => 'select',
        '#title'         => $this->t('type'),
        '#description'   => $this->t('The data type of the parameter value.'),
        '#options'       => [
          'array'   => 'array',
          'string'  => 'string',
          'integer' => 'integer',
          'number'  => 'number',
          'object'  => 'object',
          'boolean' => 'boolean',
        ],
        '#default_value' => $form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? 'string',
        '#required'      => TRUE,
      ];

      $form['api_parameter_configs']['params']['schema']['array_type'] = [
        '#type'          => 'select',
        '#title'         => $this->t('array type'),
        '#description'   => $this->t('The data type of an array item/entry '),
        '#options'       => [
          'string'        => 'string',
          'integer'       => 'integer',
          'number'        => 'number',
          'boolean'       => 'boolean',
          'any/arbitrary' => '{}',
        ],
        '#default_value' => ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? 'string' == 'array') ? $form_state->getValue(['api_parameter_configs','params','param','schema','type']) : 'string',
        '#required'      => TRUE,
      ];
      $form['api_parameter_configs']['params']['schema']['string_format'] = [
        '#type'          => 'select',
        '#title'         => $this->t('string format'),
        '#empty_option'  => $this->t(' - No format -'),
        '#description'   => $this->t(
          'Server hint for how a string should be processed.'
        ),
        '#options'       => [
          'uuid'      => 'uuid',
          'email'     => 'email',
          'date'      => 'date',
          'date-time' => 'date-time',
          'password'  => 'password',
          'byte'      => 'byte (base64  encoded)',
          'binary'    => 'binary (file)'
        ],
        '#default_value' => ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? NULL == 'string') ? $form_state->getValue(['api_parameter_configs','params','param','schema','format']) : NULL,
        '#required'      => FALSE,
      ];
      $form['api_parameter_configs']['params']['schema']['string_pattern'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Pattern'),
        '#description'   => $this->t(
          'Regular expression template for a string value e.g SSN: ^\d{3}-\d{2}-\d{4}$'
        ),
        '#default_value' =>  ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? NULL == 'string') ? $form_state->getValue(['api_parameter_configs','params','param','schema','pattern']) : NULL,
        '#required'      => FALSE,
      ];

      $form['api_parameter_configs']['params']['schema']['number_format'] = [
        '#type'          => 'select',
        '#title'         => $this->t('format'),
        '#empty_option'  => $this->t(' - No format -'),
        '#description'   => $this->t(
          'Server hint for how a number should be processed.'
        ),
        '#options'       => [
          'float'  => 'float',
          'double' => 'double',
        ],
        '#default_value' =>  ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? 'string' == 'number') ? $form_state->getValue(['api_parameter_configs','params','param','schema','format']) : NULL,
        '#required'      => FALSE,
      ];
      $form['api_parameter_configs']['params']['schema']['integer_format'] = [
        '#type'          => 'select',
        '#title'         => $this->t('format'),
        '#empty_option'  => $this->t(' - No format -'),
        '#description'   => $this->t(
          'Server hint for how a integer should be processed.'
        ),
        '#options'       => [
          'int32' => 'int32',
          'int64' => 'int64',
        ],
        '#default_value' => ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? 'string' == 'integer') ? $form_state->getValue(['api_parameter_configs','params','param','schema','format']) : NULL,
        '#required'      => FALSE,
      ];
      $form['api_parameter_configs']['params']['schema']['enum'] = [
        '#type'          => 'textfield',
        '#title'         => $this->t('Enumeration'),
        '#description'   => $this->t(
          'A controlled list of elegible options for this paramater. Use comma separated list of strings or leave empty'
        ),
        '#default_value' =>  ($form_state->getValue(['api_parameter_configs','params','param','schema','type']) ?? NULL == 'string') ? $form_state->getValue(['api_parameter_configs','params','param','schema','enum']) : NULL,
        '#required'      => FALSE,
      ];
    }

    $form['api_parameter_configs']['params']['metadata_api_configure_button'] = [
      '#type' => 'submit',
      '#name' => 'metadata_api_parameter_configure',
      '#value' => $this->t('Save parameter'),
      '#limit_validation_errors' => [['api_parameter_configs']],
      '#submit' => ['::submitAjaxAddParameter'],
      '#ajax' => [
        'callback' => '::buildAjaxAPIParameterListConfigForm',
        'wrapper' => 'api-parameters-list-form',
      ],
      '#attributes' => [
        'class' => $hide ? ['js-hide'] : [],
      ]
    ];
  }
  /**
   * Builds the configuration form listing current Parameter.
   *
   * @param array $form
   *   An associative array containing the initial structure of the plugin form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the complete form.
   */
  public function buildCurrentParametersConfigForm(array &$form, FormStateInterface $form_state) {

    /* @var MetadataAPIConfigEntity $metadataconfig */
    $metadataconfig = $this->entity->isNew() ? [] : $this->entity->get('configuration');

    $form['api_parameters_list']['table-row'] = [
      '#type' => 'table',
      '#prefix' => '<div id="table-fieldset-wrapper">',
      '#suffix' => '</div>',
      '#header' => [
        $this->t('Argument Name'),
        $this->t('Parameter Settings - Open API Schema -'),
        $this->t('Map value to View Argument/Filter'),
        $this->t('Actions'),
        $this->t('Sort'),

      ],
      '#empty' => $this->t('No configured Parameters for this API'),
      '#tabledrag' => [
        [
          'action' => 'order',
          'relationship' => 'sibling',
          'group' => 'table-sort-weight',
        ],
      ],
    ];
    $storedsettings = $metadataconfig['openAPI'] ?? [];
    if (empty(
      $form_state->get(
        'parameters'
      )
      ) && !empty($storedsettings) && !$form_state->isRebuilding()) {
      // Prepopulate our $formstate parameters variable from stored settings;.
      $form_state->set('parameters', $storedsettings);
    }
    $current_parameters = !empty(
    $form_state->get(
      'parameters'
    )
    ) ? $form_state->get('parameters') : [];
    $key = 0;
    foreach ($current_parameters as $index => $parameter_config) {
      $key++;
      $form['api_parameters_list']['table-row'][$index]['#attributes']['class'][] = 'draggable';
      $form['api_parameters_list']['table-row'][$index]['#weight'] = isset($parameter_config['weight']) ? $parameter_config['weight'] : $key;

      $form['api_parameters_list']['table-row'][$index]['name'] = [
        '#type' => 'textfield',
        '#required' => TRUE,
        '#default_value' => $index,
      ];
      // JSON ENCODED.
      $parameter_config_for_this_row = $this->formatOpenApiArgument($parameter_config);

      $form['api_parameters_list']['table-row'][$index]['param'] = [
        '#prefix' => '<pre>' . $parameter_config_for_this_row . '</pre>',
        '#type' => 'value',
        '#required' => TRUE,
        '#default_value' => $parameter_config_for_this_row,
      ];
      if ($form_state->get('views_argument_options')) {
        $form['api_parameters_list']['table-row'][$index]['mapping'] = [
          '#type'          => 'checkboxes',
          '#title'         => $this->t('Mapping'),
          '#options'       => $form_state->get('views_argument_options') ?? [],
          '#required'      => FALSE,
          // If not #validated, dynamically populated dropdowns don't work.
          '#validated'     => TRUE,
          '#default_value' => [],
        ];
      }
      else {
        $form['api_parameters_list']['table-row'][$index]['mapping'] = [
          '#type'          => 'value',
          // If not #validated, dynamically populated dropdowns don't work.
          '#validated'     => TRUE,
          '#default_value' => [],
        ];
      }
      $form['api_parameters_list']['table-row'][$index]['actions'] = [
        'delete' => [
          '#type' => 'submit',
          '#rowtodelete' => $index,
          '#name' => 'deleteitem_' . $index,
          '#value' => t('Remove'),
          // No validation.
          '#limit_validation_errors' => [['table-row']],
          // #submit required if ajax!.
          '#submit' => ['::deletePair'],
          '#ajax' => [
            'callback' => '::deleteoneCallback',
            'wrapper' => 'table-fieldset-wrapper',
          ],
        ],
        'edit' => [
          '#type' => 'submit',
          '#rowtoedit' => $index,
          '#name' => 'edititem_' . $index,
          '#value' => t('Edit'),
          // No validation.
          '#limit_validation_errors' => [['table-row']],
          // #submit required if ajax!.
          '#submit' => ['::editParameter'],
          '#ajax' => [
            'callback' => '::buildAjaxAPIParameterConfigForm',
            'wrapper' => 'api-parameters-config',
          ],
        ]
      ];

      $form['api_parameters_list']['table-row'][$index]['weight'] = [
        '#type' => 'weight',
        '#title' => $this->t(
          'Weight for @title',
          ['@title' => $index]
        ),
        '#title_display' => 'invisible',
        '#default_value' => isset($parameter_config['weight']) ? $parameter_config['weight'] : $key,
        '#attributes' => ['class' => ['table-sort-weight']],
      ];
    }
  }


  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $metadataconfig = $this->entity;

    $status = false;
    $status = $metadataconfig->save();

    if ($status) {
      $this->messenger()->addMessage(
        $this->t(
          'Saved the %label Metadata exposure endpoint.',
          [
            '%label' => $metadataconfig->label(),
          ]
        )
      );
    }
    else {
      $this->messenger()->addMessage(
        $this->t(
          'The %label Example was not saved.',
          [
            '%label' => $metadataconfig->label(),
          ]
        ),
        MessengerInterface::TYPE_ERROR
      );
    }

    $form_state->setRedirect('entity.metadataapi_entity.collection');
  }

  /**
   * Helper function to check whether an configuration entity exists.
   */
  public function exist($id) {
    $entity = $this->entityTypeManager->getStorage('metadataapi_entity')
      ->getQuery()
      ->condition('id', $id)
      ->execute();
    return (bool) $entity;
  }

  /**
   * Returns an array of view as options array, that can be used by select,
   * checkboxes and radios as #options.
   *
   * @param bool $views_only
   *   If TRUE, only return views, not displays.
   * @param string $filter
   *   Filters the views on status. Can either be 'all' (default), 'enabled' or
   *   'disabled'
   * @param mixed $exclude_view
   *   View or current display to exclude.
   *   Either a:
   *   - views object (containing $exclude_view->storage->name and $exclude_view->current_display)
   *   - views name as string:  e.g. my_view
   *   - views name and display id (separated by ':'): e.g. my_view:default
   * @param bool $optgroup
   *   If TRUE, returns an array with optgroups for each view (will be ignored for
   *   $views_only = TRUE). Can be used by select
   * @param bool $sort
   *   If TRUE, the list of views is sorted ascending.
   *
   * @return array
   *   An associative array for use in select.
   *   - key: view name and display id separated by ':', or the view name only.
   */
  private function getApplicationViewsAsOptions() {

    /*
     *
Same name and namespace in other branches
Form constructor.

Plugin forms are embedded in other forms. In order to know where the plugin form is located in the parent form, #parents and #array_parents must be known, but these are not available during the initial build phase. In order to have these properties available when building the plugin form's elements, let this method return a form element that has a #process callback and build the rest of the form in the callback. By the time the callback is executed, the element's #parents and #array_parents properties will have been set by the form API. For more documentation on #parents and #array_parents, see \Drupal\Core\Render\Element\FormElement.

Parameters

array $form: An associative array containing the initial structure of the plugin form.

\Drupal\Core\Form\FormStateInterface $form_state: The current state of the form. Calling code should pass on a subform state created through \Drupal\Core\Form\SubformState::createForSubform().

Return value

array The form structure.

Overrides SelectionPluginBase::buildConfigurationForm

File

core/modules/views/src/Plugin/EntityReferenceSelection/ViewsSelection.php, line 49
Class

ViewsSelection
Plugin implementation of the 'selection' entity_reference.
Namespace

Drupal\views\Plugin\EntityReferenceSelection
Code

public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
  $form = parent::buildConfigurationForm($form, $form_state);
  $view_settings = $this
    ->getConfiguration()['view'];
  $displays = Views::getApplicableViews('entity_reference_display');

  // Filter views that list the entity type we want, and group the separate
  // displays by view.
  $entity_type = $this->entityManager
    ->getDefinition($this->configuration['target_type']);
  $view_storage = $this->entityManager
    ->getStorage('view');
  $options = [];
  foreach ($displays as $data) {
    list($view_id, $display_id) = $data;
    $view = $view_storage
      ->load($view_id);
    if (in_array($view
      ->get('base_table'), [
      $entity_type
        ->getBaseTable(),
      $entity_type
        ->getDataTable(),
    ])) {
      $display = $view
        ->get('display');
      $options[$view_id . ':' . $display_id] = $view_id . ' - ' . $display[$display_id]['display_title'];
    }
  }

  // The value of the 'view_and_display' select below will need to be split
  // into 'view_name' and 'view_display' in the final submitted values, so
  // we massage the data at validate time on the wrapping element (not
  // ideal).
  $form['view']['#element_validate'] = [
    [
      get_called_class(),
      'settingsFormValidate',
    ],
  ];
  if ($options) {
    $default = !empty($view_settings['view_name']) ? $view_settings['view_name'] . ':' . $view_settings['display_name'] : NULL;
    $form['view']['view_and_display'] = [
      '#type' => 'select',
      '#title' => $this
        ->t('View used to select the entities'),
      '#required' => TRUE,
      '#options' => $options,
      '#default_value' => $default,
      '#description' => '<p>' . $this
        ->t('Choose the view and display that select the entities that can be referenced.<br />Only views with a display of type "Entity Reference" are eligible.') . '</p>',
    ];
    $default = !empty($view_settings['arguments']) ? implode(', ', $view_settings['arguments']) : '';
    $form['view']['arguments'] = [
      '#type' => 'textfield',
      '#title' => $this
        ->t('View arguments'),
      '#default_value' => $default,
      '#required' => FALSE,
      '#description' => $this
        ->t('Provide a comma separated list of arguments to pass to the view.'),
    ];
  }
  else {
    if ($this->currentUser
      ->hasPermission('administer views') && $this->moduleHandler
      ->moduleExists('views_ui')) {
      $form['view']['no_view_help'] = [
        '#markup' => '<p>' . $this
          ->t('No eligible views were found. <a href=":create">Create a view</a> with an <em>Entity Reference</em> display, or add such a display to an <a href=":existing">existing view</a>.', [
          ':create' => Url::fromRoute('views_ui.add')
            ->toString(),
          ':existing' => Url::fromRoute('entity.view.collection')
            ->toString(),
        ]) . '</p>',
      ];
    }
    else {
      $form['view']['no_view_help']['#markup'] = '<p>' . $this
        ->t('No eligible views were found.') . '</p>';
    }
  }
  return $form;
}
     */
    // All entity references and their extension shave this key in the
    // static::pluginManager('display')->getDefinitions();
    $displays_entity_reference = Views::getApplicableViews('entity_reference_display');
    // Only key that allows to me get REST and FEEDS
    $displays_rest = Views::getApplicableViews('returns_response');
    $options = [];
    $displays = $displays_entity_reference + $displays_rest;
    $view_storage = $this->entityTypeManager->getStorage('view');
    foreach ($displays as $data) {
      [$view_id, $display_id] = $data;
      $view = $view_storage->load($view_id);
      $display = $view->get('display');
      $options[$view_id . ':' . $display_id] = $view_id . ' - ' . $display[$display_id]['display_title'];
    }

    ksort($options);
    return $options;
  }


  private function formatOpenApiArgument(array $parameter):string {
    // Only we are calling this function (PRIVATE) so
    // i know for sure that if this is missing its because $parameter
    // Comes from a saved entity and its ready
    if (isset($parameter["weight"]) && isset($parameter["param"]) && is_array($parameter["param"])) {
      return json_encode($parameter["param"], JSON_PRETTY_PRINT);
    }

    if (empty($parameter["in"]) || empty($parameter["name"])) {
      // Prety sure something went wrong here, so return an error
      return $this->t("Something went wrong. This Parameter is wrongly setup. Please edit/delete.");
    }

    $api_argument = [
      "in" => $parameter["in"],
      "name" => $parameter["name"],
    ];
    // Schema will vary depending on the type, so let's do that.
    $schema = ['type' => $parameter['schema']['type']];
    switch ($schema['type']) {
      case 'number' :
        $schema['format'] = $parameter['schema']['number_format'];
        break;
      case 'integer' :
        $schema['format'] = $parameter['schema']['number_format'];
        break;
      case 'string' :
        $schema['format'] = $parameter['schema']['string_format'];
        $schema['pattern'] = $parameter['schema']['string_pattern'];
        if (strlen(trim($parameter['schema']['enum'])) > 0) {
          $schema['enum'] = explode(",", trim($parameter['schema']['enum']));
          foreach ($schema['enum'] as &$entry) {
            trim($entry);
          }
        }
        break;
      case 'array' :
        $schema['items']['type'] = $parameter['schema']['array_type'];
        break;
      case 'object' :
       // Not implemented yet, the form will get super complex when
        // we are here.
    }
    $api_argument['schema'] = array_filter($schema);
    switch ($api_argument['in']) {
      case 'path':
        if (in_array($parameter['style'], ['simple', 'label', 'matrix'])) {
          $api_argument['style'] = $parameter['style'];
        }
        else {
          $api_argument['style'] = 'simple';
        }
        $api_argument['explode'] = FALSE;
        $api_argument['required'] = TRUE;
        break;
      case 'query':
        if (in_array(
          $parameter['style'],
          ['form', 'spaceDelimited', 'pipeDelimited', 'deepObject']
        )
        ) {
          $api_argument['style'] = $parameter['style'];
        }
        else {
          $api_argument['style'] = 'form';
        }
        $api_argument['explode'] = TRUE;
        break;
      case 'header':
        $api_argument['style'] = 'simple';
        $api_argument['explode'] = FALSE;
        break;
      case 'cookie':
        $api_argument['style'] = 'form';
        $api_argument['explode'] = TRUE;
        break;
    }
    $api_argument['required'] =  $api_argument['required'] ?? (bool) $parameter['required'];
    $api_argument['deprecated'] = (bool) $parameter['deprecated'];
    $api_argument['description'] = $parameter['description'];
    // 'explode', mins and max not implemented via form, using the defaults for Open API 3.x
  return json_encode($api_argument, JSON_PRETTY_PRINT);
  }
}