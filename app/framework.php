<?php
/*
	Project

	This is the main object that translates the page
	from XML to HTML and applies the skin and variables.

	Get a reference to the project by calling Project::Instance();

	Developed by Matt Bell
	https://github.com/mattbell87/matts-php-framework
*/
class Project
{
	public $page;
	public $path;
	public $pageloaded = false;

	private $skinFile;
	private $error404;
	private $plugins = array();
	private $translations = array();
	private $config = array();

	private $db;

	public $rootPath;

	public $cssPath;
	public $jsPath;

	private function __construct($path = null, $skin = null)
	{
		$array = explode("/", $_SERVER['SCRIPT_NAME']);
		array_pop($array);
		$this->cssPath = '';
		$this->jsPath = '';
		$this->rootPath = implode("/", $array) . "/";
		$this->plugins["Page"] = $this->page = new Page();
		$this->page->project = $this;

		if (isset ($path))
			$this->setPath($path);

		if (isset ($skin))
			$this->setSkin($skin);

		set_error_handler(array($this, "error"));
	}

	//Instance
	public static function Instance()
    {
        static $proj = null;
        if ($proj === null)
            $proj = new Project();
        return $proj;
    }

	function setPath($path)
	{
		$this->path = $path;
		if (is_file($path))
		{
			$this->page->load($path);
			$this->pageloaded = true;
			return true;
		}
		$this->pageloaded = false;
		return false;
	}

	function setDatabase($db)
	{
		$this->db = $db;
	}

	function getDatabase()
	{
		return $this->db;
	}

	function setSkin($skin)
	{
		$this->skinFile = $skin;
	}

	function set404NotFound($path)
	{
		$this->error404 = $path;
	}

	function process()
	{
		//Call a translate after binding plugins
		$this->page->setContent($this->page->content());

		if (file_exists($this->skinFile))
		{
			if (!$this->pageloaded)
			{
				//Output 404 error
				header('HTTP/1.0 404 Not Found');
				if (isset($this->error404))
					$this->setPath($this->error404);
				else
					$this->setPath('./app/error/error404.xml');
			}
			$content = file_get_contents($this->skinFile);
			$content = $this->translate($content);
			print $content;
		}
		else
		{
			header('HTTP/1.0 500 Internal Server Error');
			print "<h1>Error 500 - Internal Server Error</h1><p>Template file not found</p>";
		}
	}

	function setConfig($name, $value)
	{
		$this->config[$name] = $value;
	}

	function getConfig($name)
	{
		return $this->config[$name];
	}

	function error($errno, $errstr, $errfile = "", $errline = "")
	{
		if ($errfile != "")
				$errfile = "<p>File: $errfile</p>";
		if ($errline != "")
				$errline = "<p>Line: $errline</p>";

		switch ($errno)
		{
		case E_USER_ERROR:
			echo "<div class=\"error-box\"><p><b>Error</b> [$errno] $errstr</p> $errfile $errline</div>\n";
			exit(1);
			break;

		case E_USER_WARNING:
			echo "<div class=\"error-box\"><p><b>Warning</b> [$errno] $errstr</p> $errfile $errline</div>\n";
			break;

		case E_USER_NOTICE:
			echo "<div class=\"error-box\"><p><b>Notice</b> [$errno] $errstr</p> $errfile $errline</div>\n";
			break;

		default:
			echo "<div class=\"error-box\"><p><b>Error</b> [$errno] $errstr</p> $errfile $errline</div>\n";
			break;
		}

		return true;
	}

	function translate($content)
	{
		$commands = array();
		preg_match_all("/\{\{([^}]*)\}\}/", $content, $commands);

		$getParams = function($paramstr)
		{
            $params = array();
            $matches = array();
            preg_match('/\((.*)\)/',$paramstr,$matches);
            $paramstr = $matches[1]; //first group of capture
            $matches = preg_split("/(?<!\\\\),/",$paramstr);
            foreach ($matches as $match)
            {
                $match = explode('=', $match, 2);

                if (count($match) >= 2)
					$params[$match[0]] = str_replace('\\,', ',', $match[1]);
				else if (count($match) == 1)
					$params[$match[0]] = TRUE;
            }

            return $params;
		};

		if (count($commands) > 0)
		{
			foreach ($commands[1] as $command)
			{
				$command = html_entity_decode($command);
                $commandArray = explode(".", $command, 2);
                $cmd = str_replace('/', '\\/', preg_quote($command));
				$str = '';

				if (count($commandArray) > 1)
				{
					$plugin = $commandArray[0];
					$method = $commandArray[1];
					$params = array();

                    if (strpos($method, "(") !== FALSE)
					{
                        $params = $getParams($method);
                        $arrmethod = explode("(", $method);
						$method = $arrmethod[0];
					}

					if (isset($this->plugins[$plugin]))
					{
						ob_start();
						try
						{
							if (count($params) > 0)
								$str = call_user_func(array($this->plugins[$plugin], $method), $params);
							else
								$str = call_user_func(array($this->plugins[$plugin], $method));
						}
						catch(Exception $e)
						{
							$str = $e->getMessage();
						}
						$str .= ob_get_clean();
						$replace = str_replace('$', '\$', $str);
						$content = preg_replace('/\{\{'.$cmd.'\}\}/',$replace,$content);
					}
					else if ($plugin == "include")
					{
						ob_start();

						$path = dirname($this->path);
						$params = $getParams($method);
						if (isset($params["file"]))
						{
							$include = $path . str_replace("\"", "", $params["file"]);
							include($include);
						}
						$str .= ob_get_clean();
						$replace = str_replace('$', '\$', $str);
						$content = preg_replace('/\{\{'.$cmd.'\}\}/',$replace,$content);
					}
				}
				elseif (count($commandArray) > 0)
				{
					$str = "";
					if ($command == "root")
						$str = $this->rootPath;
					$replace = str_replace('$', '\$', $str);
					$content = preg_replace('/\{\{'.$cmd.'\}\}/',$replace,$content);
				}
			}
		}

        //Remove server side only content
        $content = preg_replace('/xmleditable="[^"]*"/','',$content);
		return $content;
	}

	function addPlugin($name)
	{
		require_once "plugins/" . $name;
		$this->bindPlugins();
	}

	function bindPlugins()
	{
		$classes = get_declared_classes();
		foreach($classes as $class)
		{
			if
			(
				get_parent_class($class) == 'Plugin' &&
				!isset($this->plugins[$class])
			)
			{
				$this->plugins[$class] = new $class();
                $this->plugins[$class]->project = $this;
				$this->plugins[$class]->pluginLoaded();
			}
		}
	}

	//Access plugins from each other
	function plugin($name)
	{
        if (isset($this->plugins[$name]))
            return $this->plugins[$name];
        else
            return null;
	}
}

/*
	Plugin

    Defines a base class for plugins
*/
abstract class Plugin
{
	public function pluginLoaded(){}
}


/*
	Page plugin

	This is a built-in plugin that handles pages.
*/
class Page extends Plugin
{
	public $xml;

	private $content;
	private $path;
	private $scriptEls = array();
	private $styleEls = array();

	function __construct()
	{
		$this->xml = new SimpleXMLElement("<page><title></title><content></content></page>");
	}

	function innerText($element)
	{
		return (strip_tags($element->asXml()));
	}

	function innerXML($element)
	{
		$innerXML= '';
		foreach (dom_import_simplexml($element)->childNodes as $child)
		{
			$innerXML .= $child->ownerDocument->saveXML( $child );
		}
		return $innerXML;
	}

	function getValue($tagname)
	{
		foreach ($this->xml->children() as $child)
		{
			$name = $child->getName();
			if ($name == $tagname)
				return $this->innerText($child);
		}
	}

	function getInnerXML($tagname)
	{
		foreach ($this->xml->children() as $child)
		{
			$name = $child->getName();
			if ($name == $tagname)
				return $this->innerXML($child);
		}
	}

	function load($path)
	{
		$this->path = $path;
		$this->xml = new SimpleXMLElement($this->path,0,true);

		//Load content
		$this->content = $this->innerXML($this->xml->content);

		//Expand any HTML tags that can't be self-closed
		$this->content = $this->expandToHTML($this->content);

		//Handle XML includes
		foreach ($this->xml->children() as $child)
		{
			$name = $child->getName();
			//Load XML specific plugins
			if ($name == "plugin")
			{
				$pluginname = $this->innerText($child);
				$this->project->addPlugin($pluginname);
			}
			//Load XML specific JavaScript
			else if ($name == "js")
			{
				$href = $this->innerText($child);
				if (strpos($href,"://") === false)
					$href = $this->project->rootPath . $this->project->jsPath . $href;
				$script = "<script src=\"$href\" type=\"text/javascript\"></script>";
				array_push($this->scriptEls, $script);
			}
			//Load XML specific CSS
			else if ($name == "css")
			{
				$href = $this->innerText($child);
				if (strpos($href,"://") === false)
					$href = $this->project->rootPath . $this->project->cssPath . $href;
				$media = $child->attributes()->media;
				$style = '<link rel="stylesheet" href="'.$href .'" media="'.$media.'" />';
				array_push($this->styleEls, $style);
			}
		}

	}

	function path()
	{
		return $this->path;
	}

	function scripts()
	{
		$html = implode("\n", $this->scriptEls);
		return $html;

	}

	function styles()
	{
		$html = implode("\n", $this->styleEls);
		return $html;
	}

	function content()
	{
		return ($this->content);
	}

	function setContent($newcontent)
	{
		$this->content = $this->project->translate($newcontent);
	}

	function title($cmds = NULL)
	{
		$output = "";

		if (isset($this->xml->title))
			$output = $this->xml->title;
		else
			$output = "Title not found";

		if (isset($cmds['notags']))
			$output = strip_tags($output);

		return $this->project->translate($output);
	}

	function setTitle($newTitle)
	{
		$this->xml->title = $newTitle;
	}

	function metadata()
	{
		$metadata = "";
		foreach ($this->xml->children() as $child)
		{
			$name = $child->getName();
			if ($name == "meta")
				$metadata .= $child->ownerDocument->saveXML( $child );
		}

		return $metadata;
	}

	//Convert a tag from self closed to no contents (for better HTML compatibility)
	public function expandToHTML($code)
	{
		//Define HTML tags that can't be self closed
		$tags = array
		(
			'textarea',
			'link',
			'td',
			'div',
			'span',
			'p',
			'a'
		);

		//Expand each tag
		foreach ($tags as $tag)
		{
			$code = $this->expandTag($tag, $code);
		}

		return $code;
	}

	private function expandTag($tag, $content)
	{
		if (strpos($this->content, "<$tag") !== false)
		{
			$regtag = preg_quote($tag);
			$content = preg_replace("/<$regtag([^>]*)(?=\/>)\/>/", "<$regtag$1></$regtag>", $content);
		}
		return $content;
	}
}

?>
