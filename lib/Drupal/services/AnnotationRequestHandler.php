<?php

/**
 * @file
 * Definition of Drupal\rest\RequestHandler.
 */

namespace Drupal\services;

use Drupal\rest\RequestHandler;
use Symfony\Cmf\Component\Routing\RouteObjectInterface;
use Symfony\Component\DependencyInjection\ContainerAware;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;
use Symfony\Component\Serializer\Exception\UnexpectedValueException;

/**
 * Acts as intermediate request forwarder for resource plugins.
 */
class AnnotationRequestHandler extends ContainerAware {

  /**
   * Handles a web API request.
   *
   * @param Symfony\Component\HttpFoundation\Request $request
   *   The HTTP request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function handle(Request $request) {
    $route = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT);
    $plugin = $route->getDefault('_plugin');
    $operation = $route->getRequirement('_operation');

    $resource = $this->container
      ->get('plugin.manager.rest')
      ->getInstance(array('id' => $plugin));

    // Deserialize incoming data if available.
    $serializer = $this->container->get('serializer');
    $received = $request->getContent();
    $unserialized = NULL;
    if (!empty($received)) {
      $format = $request->getContentType();

      // Only allow serialization formats that are explicitly configured. If no
      // formats are configured allow all and hope that the serializer knows the
      // format. If the serializer cannot handle it an exception will be thrown
      // that bubbles up to the client.
      $config = $this->container->get('config.factory')->get('rest.settings')->get('resources');
      $enabled_formats = isset($config[$plugin][$request->getMethod()]) ? $config[$plugin][$request->getMethod()] : array();
      if (empty($enabled_formats) || isset($enabled_formats[$format])) {
        $unserialized = json_decode($received);
//        $definition = $resource->getDefinition();
//        $class = $definition['serialization_class'];
//        try {
//          $unserialized = $serializer->deserialize($received, $class, $format);
//        }
//        catch (UnexpectedValueException $e) {
//          $error['error'] = $e->getMessage();
//          $content = $serializer->serialize($error, $format);
//          return new Response($content, 400, array('Content-Type' => $request->getMimeType($format)));
//        }
      }
      else {
        throw new UnsupportedMediaTypeHttpException();
      }
    }

    // Invoke the operation on the resource plugin.
    // All REST routes are restricted to exactly one format, so instead of
    // parsing it out of the Accept headers again, we can simply retrieve the
    // format requirement. If there is no format associated, just pick HAL.
    $format = $request->attributes->get(RouteObjectInterface::ROUTE_OBJECT)->getRequirement('_format') ?: 'hal_json';
    try {
      $operation_annotation = $resource->getMethodAnnotation($operation);
      $arguments = $this->getArguments($operation_annotation, $request, $unserialized);

      $response = $resource->{$operation}($arguments, $unserialized, $request);
    }
    catch (HttpException $e) {
      $error['error'] = $e->getMessage();
      $content = $serializer->serialize($error, $format);
      // Add the default content type, but only if the headers from the
      // exception have not specified it already.
      $headers = $e->getHeaders() + array('Content-Type' => $request->getMimeType($format));
      return new Response($content, $e->getStatusCode(), $headers);
    }

    // Serialize the outgoing data for the response, if available.
    $data = $response->getResponseData();
    if ($data != NULL) {
      $output = $serializer->serialize($data, $format);
      $response->setContent($output);
      $response->headers->set('Content-Type', $request->getMimeType($format));
    }
    return $response;
  }

  protected function getArguments($operation_annotation, $request, $unserialized) {
    if (!isset($operation_annotation['parameters'])) {
      return;
    }

    $arguments = array();

    foreach ($operation_annotation['parameters'] as $parameter_name => $parameter_info) {
      $argument_value = NULL;
      switch ($parameter_info['location']) {
        case 'uri':
          $argument_value = $request->attributes->get($parameter_name);
          break;
        case 'body':
          $unserialized = (array) $unserialized;
          $argument_value = isset($unserialized[$parameter_name]) ? $unserialized[$parameter_name] : NULL;
          break;
      }

      $arguments[$parameter_name] = $argument_value;
    }

    return $arguments;
  }

  /**
   * Generates a CSRF protecting session token.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response object.
   */
  public function csrfToken() {
    return new Response(drupal_get_token('rest'), 200, array('Content-Type' => 'text/plain'));
  }
}
