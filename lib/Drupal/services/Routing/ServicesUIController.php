<?php

/**
 * @file
 * Contains \Drupal\views_ui\Routing\ViewsUIController.
 */

namespace Drupal\services\Routing;

//use Drupal\views\ViewExecutable;
//use Drupal\views\ViewStorageInterface;
//use Drupal\views_ui\ViewUI;
//use Drupal\views\ViewsDataCache;
use Drupal\user\TempStore;
use Drupal\user\TempStoreFactory;
use Drupal\Core\ControllerInterface;
use Drupal\Core\Entity\EntityManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
//use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
//use Drupal\Core\Ajax\AjaxResponse;
//use Drupal\Core\Ajax\ReplaceCommand;

/**
 * Returns responses for Services admin routes.
 */
class ServicesUIController implements ControllerInterface {

  /**
   * Stores the Entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManager
   */
  protected $entityManager;

  /**
   * Stores the Views data cache object.
   *
   * @var \Drupal\views\ViewsDataCache
   */
  protected $viewsData;

  /**
   * Stores the user tempstore.
   *
   * @var \Drupal\user\TempStore
   */
  protected $tempStore;

  /**
   * Constructs a new \Drupal\views_ui\Routing\ViewsUIController object.
   *
   * @param \Drupal\Core\Entity\EntityManager $entity_manager
   *   The Entity manager.
   */
  public function __construct(EntityManager $entity_manager) {
    $this->entityManager = $entity_manager;
  }

  /**
   * Implements \Drupal\Core\ControllerInterface::create().
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.entity')
    );
  }

  /**
   * Lists all of the endpoints.
   *
   * @return array
   *   The Endpoints listing page.
   */
  public function listing() {
    return $this->entityManager->getListController('endpoint')->render();
  }

  /**
   * Returns the form to add a new view.
   *
   * @return array
   *   The Endpoint add form.
   */
  public function add() {
    drupal_set_title(t('Add new endpoint'));

    $entity = $this->entityManager->getStorageController('endpoint')->create(array());
    return entity_get_form($entity, 'add');
  }

  /**
   * Lists all plugins and what enabled Views use them.
   *
   * @return array
   *   The Views plugins report page.
   */
  public function reportPlugins() {
    $rows = views_plugin_list();
    foreach ($rows as &$row) {
      // Link each view name to the view itself.
      foreach ($row['views'] as $row_name => $view) {
        $row['views'][$row_name] = l($view, "admin/structure/views/view/$view");
      }
      $row['views'] = implode(', ', $row['views']);
    }

    // Sort rows by field name.
    ksort($rows);
    return array(
      '#theme' => 'table',
      '#header' => array(t('Type'), t('Name'), t('Provided by'), t('Used in')),
      '#rows' => $rows,
      '#empty' => t('There are no enabled views.'),
    );
  }

  /**
   * Calls a method on a view and reloads the listing page.
   *
   * @param \Drupal\views\ViewStorageInterface $view
   *   The view being acted upon.
   * @param string $op
   *   The operation to perform, e.g., 'enable' or 'disable'.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse|\Symfony\Component\HttpFoundation\RedirectResponse
   *   Either returns a rebuilt listing page as an AJAX response, or redirects
   *   back to the listing page.
   */
  public function ajaxOperation(ViewStorageInterface $view, $op, Request $request) {
    // Perform the operation.
    $view->$op()->save();

    // If the request is via AJAX, return the rendered list as JSON.
    if ($request->request->get('js')) {
      $list = $this->entityManager->getListController('view')->render();
      $response = new AjaxResponse();
      $response->addCommand(new ReplaceCommand('#views-entity-list', drupal_render($list)));
      return $response;
    }

    // Otherwise, redirect back to the page.
    // @todo Remove url() wrapper once http://drupal.org/node/1668866 is in.
    return new RedirectResponse(url('admin/structure/views', array('absolute' => TRUE)));
  }

  /**
   * Returns the form to clone a view.
   *
   * @param \Drupal\views\ViewStorageInterface $view
   *   The view being cloned.
   *
   * @return array
   *   The Views clone form.
   */
  public function cloneForm(ViewStorageInterface $view) {
    drupal_set_title(t('Clone of @label', array('@label' => $view->label())));
    return entity_get_form($view, 'clone');
  }

  /**
   * Menu callback for Views tag autocompletion.
   *
   * Like other autocomplete functions, this function inspects the 'q' query
   * parameter for the string to use to search for suggestions.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions for Views tags.
   */
  public function autocompleteTag(Request $request) {
    $matches = array();
    $string = $request->query->get('q');
    // Get matches from default views.
    $views = $this->entityManager->getStorageController('view')->load();
    foreach ($views as $view) {
      $tag = $view->get('tag');
      if ($tag && strpos($tag, $string) === 0) {
        $matches[$tag] = $tag;
        if (count($matches) >= 10) {
          break;
        }
      }
    }

    return new JsonResponse($matches);
  }

  /**
   * Returns the form to edit a view.
   *
   * @param \Drupal\views_ui\ViewUI $view
   *   The view being deleted.
   * @param string|null $display_id
   *   (optional) The display ID being edited. Defaults to NULL, which will load
   *   the first available display.
   *
   * @return array
   *   An array containing the Views edit and preview forms.
   */
  public function edit(ViewUI $view, $display_id = NULL) {
    $name = $view->label();
    $data = $this->viewsData->get($view->get('base_table'));

    if (isset($data['table']['base']['title'])) {
      $name .= ' (' . $data['table']['base']['title'] . ')';
    }
    drupal_set_title($name);

    $build['edit'] = entity_get_form($view, 'edit', array('display_id' => $display_id));
    $build['preview'] = entity_get_form($view, 'preview', array('display_id' => $display_id));
    return $build;
  }

  /**
   * Returns the form to preview a view.
   *
   * @param \Drupal\views_ui\ViewUI $view
   *   The view being deleted.
   * @param string|null $display_id
   *   (optional) The display ID being edited. Defaults to NULL, which will
   *   load the first available display.
   *
   * @return array
   *   The Views preview form.
   */
  public function preview(ViewUI $view, $display_id = NULL) {
    return entity_get_form($view, 'preview', array('display_id' => $display_id));
  }

}
