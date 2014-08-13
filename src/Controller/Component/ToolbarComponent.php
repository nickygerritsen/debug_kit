<?php
/**
 * DebugKit DebugToolbar Component
 *
 * Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 *
 * Licensed under The MIT License
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (http://cakefoundation.org)
 * @link          http://cakephp.org CakePHP(tm) Project
 * @since         DebugKit 0.1
 * @license       http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace DebugKit\Controller\Component;

use Cake\Cache\Cache;
use Cake\Controller\Component;
use Cake\Controller\ComponentRegistry;
use Cake\Controller\Controller;
use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Event\Event;
use Cake\Log\Log;
use Cake\Log\LogInterface;
use Cake\Utility\Inflector;
use DebugKit\DebugMemory;
use DebugKit\DebugPanel;
use DebugKit\DebugTimer;

/**
 * Class ToolbarComponent
 *
 * @since         DebugKit 0.1
 */
class ToolbarComponent extends Component {

/**
 * Settings for the Component
 *
 * - forceEnable - Force the toolbar to display even if debug == 0. Default = false
 * - autoRun - Automatically display the toolbar. If set to false, toolbar display can be triggered by adding
 *    `?debug=true` to your URL.
 *
 * @var array
 */
	public $_defaultConfig = array(
		'forceEnable' => false,
		'autoRun' => true
	);

/**
 * Controller instance reference
 *
 * @var object
 */
	public $controller;

/**
 * Components used by DebugToolbar
 *
 * @var array
 */
	public $components = array('RequestHandler', 'Session');

/**
 * The default panels the toolbar uses.
 * which panels are used can be configured when attaching the component
 *
 * @var array
 */
	protected $_defaultPanels = array(
		'DebugKit.History',
		'DebugKit.Session',
		'DebugKit.Request',
		'DebugKit.SqlLog',
		'DebugKit.Timer',
		'DebugKit.Log',
		'DebugKit.Variables',
		'DebugKit.Environment',
		'DebugKit.Include'
	);

/**
 * Loaded panel objects.
 *
 * @var array
 */
	public $panels = array();

/**
 * javascript files component will be using
 *
 * @var array
 */
	public $javascript = array(
		'libs' => 'DebugKit./js/js_debug_toolbar'
	);

/**
 * CSS files component will be using
 *
 * @var array
 */
	public $css = array('DebugKit./css/debug_toolbar.css');

/**
 * CacheKey used for the cache file.
 *
 * @var string
 */
	public $cacheKey = 'toolbar_cache';

/**
 * Duration of the debug kit history cache
 *
 * @var string
 */
	public $cacheDuration = '+4 hours';

/**
 * Constructor
 *
 * If debug is off the component will be disabled and not do any further time tracking
 * or load the toolbar helper.
 *
 * @param ComponentCollection $collection
 * @param array $settings
 * @return void
 */
	public function __construct(ComponentRegistry $collection, $settings = array()) {
		$settings = array_merge((array)Configure::read('DebugKit'), $settings);
		$panels = $this->_defaultPanels;
		if (isset($settings['panels'])) {
			$panels = $this->_makePanelList($settings['panels']);
			unset($settings['panels']);
		}
		parent::__construct($collection, (array)$settings);

		$this->controller = $collection->getController();

		$enabled = true;
		if (
			!Configure::read('debug') &&
			empty($this->settings['forceEnable'])
		) {
			return;
		}
		if (
			$this->settings['autoRun'] === false &&
			!isset($this->controller->request->query['debug'])
		) {
			return;
		}

		DebugMemory::record(__d('debug_kit', 'Component initialization'));

		$this->cacheKey .= $this->Session->read('Config.userAgent');
		if (
			in_array('DebugKit.History', $panels) ||
			(isset($settings['history']) && $settings['history'] !== false)
		) {
			$this->_createCacheConfig();
		}

		$this->_loadPanels($panels, $settings);
	}

/**
 * Register all the timing handlers for core events.
 *
 * @return array
 */
	public function implementedEvents() {
		$before = function ($name) {
			return function () use ($name) {
				DebugTimer::start($name, __d('debug_kit', $name));
			};
		};
		$after = function ($name) {
			return function () use ($name) {
				DebugTimer::stop($name);
			};
		};

		return array(
			'Controller.initialize' => array(
				array('priority' => 0, 'callable' => $before('Event: Controller.initialize')),
				array('priority' => 999, 'callable' => $after('Event: Controller.initialize'))
			),
			'Controller.startup' => array(
				array('priority' => 0, 'callable' => $before('Event: Controller.startup')),
				array('priority' => 999, 'callable' => $after('Event: Controller.startup')),
				array('priority' => 10, 'callable' => 'startup'),
			),
			'Controller.beforeRender' => array(
				array('priority' => 0, 'callable' => $before('Event: Controller.beforeRender')),
				array('priority' => 999, 'callable' => $after('Event: Controller.beforeRender')),
				array('priority' => 10, 'callable' => 'beforeRender'),
			),
			'Controller.beforeRedirect' => 'beforeRedirect',
			'Controller.shutdown' => array(
				array('priority' => 0, 'callable' => $before('Event: Controller.shutdown')),
				array('priority' => 999, 'callable' => $after('Event: Controller.shutdown'))
			),
			'View.beforeRender' => array(
				array('priority' => 0, 'callable' => $before('Event: View.beforeRender')),
				array('priority' => 999, 'callable' => $after('Event: View.beforeRender'))
			),
			'View.afterRender' => array(
				array('priority' => 0, 'callable' => $before('Event: View.afterRender')),
				array('priority' => 999, 'callable' => $after('Event: View.afterRender'))
			),
			'View.beforeLayout' => array(
				array('priority' => 0, 'callable' => $before('Event: View.beforeLayout')),
				array('priority' => 999, 'callable' => $after('Event: View.beforeLayout'))
			),
			'View.afterLayout' => array(
				array('priority' => 0, 'callable' => $before('Event: View.afterLayout')),
				array('priority' => 999, 'callable' => $after('Event: View.afterLayout'))
			),
		);
	}

/**
 * Go through user panels and remove default panels as indicated.
 *
 * @param array $userPanels The list of panels ther user has added removed.
 * @return array Array of panels to use.
 */
	protected function _makePanelList($userPanels) {
		$panels = $this->_defaultPanels;
		foreach ($userPanels as $key => $value) {
			if (is_numeric($key)) {
				$panels[] = $value;
			}
			if (is_string($key) && $value === false) {
				$index = array_search($key, $panels);
				if ($index !== false) {
					unset($panels[$index]);
				}
				// Compatibility for when panels were not
				// required to have a plugin prefix.
				$alternate = 'DebugKit.' . ucfirst($key);
				$index = array_search($alternate, $panels);
				if ($index !== false) {
					unset($panels[$index]);
				}
			}
		}
		return $panels;
	}

/**
 * Component Startup
 *
 * @param Controller $controller
 * @return boolean
 */
	public function startup(Event $event) {
		$controller = $event->subject();
		$panels = array_keys($this->panels);
		foreach ($panels as $panelName) {
			$this->panels[$panelName]->startup($controller);
		}
		DebugTimer::start(
			'controllerAction',
			__d('debug_kit', 'Controller action')
		);
		DebugMemory::record(
			__d('debug_kit', 'Controller action start')
		);
	}

/**
 * beforeRedirect callback
 *
 * @param Controller $controller
 * @param $url
 * @param null $status
 * @param boolean $exit
 * @return void
 */
	public function beforeRedirect(Event $event, $url, $status = null, $exit = true) {
		DebugTimer::stop('controllerAction');
		DebugTimer::start(
			'processToolbar',
			__d('debug_kit', 'Processing toolbar state')
		);
		$controller = $event->subject();
		$vars = $this->_gatherVars($controller);
		$this->_saveState($controller, $vars);
		DebugTimer::stop('processToolbar');
	}

/**
 * beforeRender callback
 *
 * Calls beforeRender on all the panels and set the aggregate to the controller.
 *
 * @param Controller $controller
 * @return void
 */
	public function beforeRender(Event $event) {
		DebugTimer::stop('controllerAction');

		DebugTimer::start(
			'processToolbar',
			__d('debug_kit', 'Processing toolbar data')
		);
		$controller = $event->subject();
		$vars = $this->_gatherVars($controller);
		$this->_saveState($controller, $vars);

		$this->javascript = array_unique(array_merge($this->javascript, $vars['javascript']));
		$this->css = array_unique(array_merge($this->css, $vars['css']));
		unset($vars['javascript'], $vars['css']);

		$controller->set(array(
			'debugToolbarPanels' => $vars,
			'debugToolbarJavascript' => $this->javascript,
			'debugToolbarCss' => $this->css
		));

		$isHtml = (
			!isset($controller->request->params['ext']) ||
			$controller->request->params['ext'] === 'html'
		);

		if (!$controller->request->is('ajax') && $isHtml) {
			$format = 'Html';
		} else {
			$format = 'FirePhp';
		}

		$controller->helpers[] = 'DebugKit.DebugTimer';
		$controller->helpers['DebugKit.Toolbar'] = array(
			'output' => sprintf('DebugKit.%sToolbar', $format),
			'cacheKey' => $this->cacheKey,
			'cacheConfig' => 'debug_kit',
			'forceEnable' => $this->settings['forceEnable'],
		);

		DebugTimer::stop('processToolbar');
		DebugMemory::record(__d('debug_kit', 'Controller render start'));
	}

/**
 * Load a toolbar state from cache
 *
 * @param integer $key
 * @return array
 */
	public function loadState($key) {
		$history = Cache::read($this->cacheKey, 'debug_kit');
		if (isset($history[$key])) {
			return $history[$key];
		}
		return array();
	}

/**
 * Create the cache config for the history
 *
 * @return void
 */
	protected function _createCacheConfig() {
		if (Configure::read('Cache.disable') === true || Cache::config('debug_kit')) {
			return;
		}
		$cache = array(
		    'duration' => $this->cacheDuration,
		    'engine' => 'File',
		    'path' => CACHE
		);
		if (isset($this->settings['cache'])) {
			$cache = array_merge($cache, $this->settings['cache']);
		}
		Cache::config('debug_kit', $cache);
	}

/**
 * collects the panel contents
 *
 * @param Controller $controller
 * @return array Array of all panel beforeRender()
 */
	protected function _gatherVars(Controller $controller) {
		$vars = array('javascript' => array(), 'css' => array());
		$panels = array_keys($this->panels);

		foreach ($panels as $panelName) {
			$panel = $this->panels[$panelName];
			$panelName = Inflector::underscore($panelName);
			$vars[$panelName]['content'] = $panel->beforeRender($controller);
			$elementName = Inflector::underscore($panelName) . '_panel';
			if (isset($panel->elementName)) {
				$elementName = $panel->elementName;
			}
			$vars[$panelName]['elementName'] = $elementName;
			$vars[$panelName]['plugin'] = $panel->plugin;
			$vars[$panelName]['title'] = $panel->title;
			$vars[$panelName]['disableTimer'] = true;

			if (!empty($panel->javascript)) {
				$vars['javascript'] = array_merge($vars['javascript'], (array)$panel->javascript);
			}
			if (!empty($panel->css)) {
				$vars['css'] = array_merge($vars['css'], (array)$panel->css);
			}
		}
		return $vars;
	}

/**
 * Load Panels used in the debug toolbar
 *
 * @param $panels
 * @param $settings
 * @return void
 */
	protected function _loadPanels($panels, $settings) {
		foreach ($panels as $panel) {
			$className = App::className($panel, 'Panel', 'Panel');
			if ($className === false) {
				throw new \RuntimeException(
					__d('debug_kit', 'Could not load DebugToolbar panel %s', $panel)
				);
				continue;
			}
			$panelObj = new $className($settings);
			if ($panelObj instanceof DebugPanel) {
				list(, $panel) = pluginSplit($panel);
				$this->panels[Inflector::underscore($panel)] = $panelObj;
			}
		}
	}

/**
 * Save the current state of the toolbar varibles to the cache file.
 *
 * @param \Controller|object $controller Controller instance
 * @param array $vars Vars to save.
 * @return void
 */
	protected function _saveState(Controller $controller, $vars) {
		$config = Cache::config('debug_kit');
		if (empty($config) || !isset($this->panels['history'])) {
			return;
		}
		$history = Cache::read($this->cacheKey, 'debug_kit');
		if (empty($history)) {
			$history = array();
		}
		if (count($history) == $this->panels['history']->history) {
			array_pop($history);
		}

		if (isset($vars['variables']['content'])) {
			// Remove unserializable native objects.
			array_walk_recursive($vars['variables']['content'], function (&$item) {
				if (
					$item instanceof Closure ||
					$item instanceof PDO ||
					$item instanceof SimpleXmlElement
				) {
					$item = 'Unserializable object - ' . get_class($item);
				} elseif ($item instanceof Exception) {
					$item = sprintf(
						'Unserializable object - %s. Error: %s in %s, line %s',
						get_class($item),
						$item,
						$item->getMessage(),
						$item->getFile(),
						$item->getLine()
					);
				}
				return $item;
			});
		}
		unset($vars['history']);
		array_unshift($history, $vars);
		Cache::write($this->cacheKey, $history, 'debug_kit');
	}

}