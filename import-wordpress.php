<?php
PHP_SAPI === 'cli' or die();

/**
 * File       wordpress_import.php
 * Created    11/26/14 7:52 PM
 * Author     Matt Thomas | matt@betweenbrain.com | http://betweenbrain.com
 * Support    https://github.com/betweenbrain/
 * Copyright  Copyright (C) 2014 betweenbrain llc. All Rights Reserved.
 * License    GNU GPL v2 or later
 */

// We are a valid entry point.
const _JEXEC = 1;

// Load system defines
if (file_exists(dirname(__DIR__) . '/defines.php'))
{
	require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('_JDEFINES'))
{
	define('JPATH_BASE', dirname(__DIR__));
	require_once JPATH_BASE . '/includes/defines.php';
}

// Get the framework.
require_once JPATH_LIBRARIES . '/import.legacy.php';

// Bootstrap the CMS libraries.
require_once JPATH_LIBRARIES . '/cms.php';

// Configure error reporting to maximum for CLI output.
error_reporting(E_ALL);
ini_set('display_errors', 1);

/**
 * A command line cron job to attempt to remove files that should have been deleted at update.
 *
 * @package  Joomla.Cli
 * @since    3.0
 */
class ImportwordpressCli extends JApplicationCli
{

	/**
	 * @var null
	 */
	private $csvData = null;

	/**
	 * The URL of the CSV file to import
	 *
	 * @var null
	 */
	private $csvUrl = null;

	/**
	 * @var null
	 */
	private $columnMap = null;

	/**
	 * Constructor.
	 *
	 * @param   object &$subject The object to observe
	 * @param   array  $config   An optional associative array of configuration settings.
	 *
	 * @since   1.0.0
	 */
	public function __construct()
	{
		parent::__construct();
		$this->db = JFactory::getDbo();

		// Set JFactory::$application object to avoid system using incorrect defaults
		JFactory::$application = $this;

		$this->csvUrl    = 'https://docs.google.com/spreadsheets/d/1kjHIIGag094UaSPgUsHL6nIgCHX3X2SUPYo3abD9eCk/export?format=csv';
		$csvData         = $this->readCSVFile($this->csvUrl);
		$this->columnMap = $this->mapColumnNames($csvData);
		array_shift($csvData);
		$this->csvData = $csvData;
	}

	/**
	 * Parses the guid to return the post ID
	 *
	 * @param $item
	 *
	 * @return mixed
	 */
	private function postId($item)
	{
		return end(explode('?p=', $item->guid));
	}

	/**
	 * Entry point for CLI script
	 *
	 * @return  void
	 *
	 * @since   3.0
	 */
	public function execute()
	{

		if ($this->input->get('v'))
		{
			$this->out(JProfiler::getInstance('Application')->mark('Starting import.'));
		}

		foreach ($this->csvData as $feed)
		{
			$xml = simplexml_load_file($feed[$this->columnMap->feedUrl], 'SimpleXMLElement', LIBXML_NOCDATA);

			foreach ($xml->channel->item as $item)
			{
				$this->save($item, $feed[$this->columnMap->categoryId]);
			}
		}

		if ($this->input->get('v'))
		{
			$this->out(JProfiler::getInstance('Application')->mark('Finished import.'));
		}
	}

	/**
	 * Retrieve the admin user id.
	 *
	 * @return  int|bool One Administrator ID
	 *
	 * @since   3.2
	 */
	private function getAdminId()
	{
		$db    = JFactory::getDbo();
		$query = $db->getQuery(true);

		// Select the required fields from the updates table
		$query
			->clear()
			->select('u.id')
			->from('#__users as u')
			->join('LEFT', '#__user_usergroup_map AS map ON map.user_id = u.id')
			->join('LEFT', '#__usergroups AS g ON map.group_id = g.id')
			->where('g.title = ' . $db->q('Super Users'));

		$db->setQuery($query);
		$id = $db->loadResult();

		if (!$id || $id instanceof Exception)
		{
			return false;
		}

		return $id;
	}

	/**
	 * Use PHP Simple HTML DOM Parser to get the post fulltext
	 *
	 * @param $url
	 *
	 * @return mixed
	 */
	private function getFullText($url)
	{
		include_once dirname(__FILE__) . '/simple_html_dom.php';
		$html = file_get_html($url);

		foreach ($html->find('.entry-content') as $entry)
		{
			$content[] = $entry->innertext;
		}

		return implode("\n", $content);
	}

	/**
	 * Checks if an article already exists based on the article alias derived from the column "name"
	 *
	 * @param $article
	 *
	 * @return bool
	 */
	private function isDuplicate($xref)
	{
		$query = $this->db->getQuery(true);
		$query
			->select($this->db->quoteName('id'))
			->from($this->db->quoteName('#__content'))
			->where($this->db->quoteName('xreference') . ' = ' . $this->db->quote($xref));
		$this->db->setQuery($query);

		return $this->db->loadResult() ? true : false;
	}

	/**
	 * Read the first row of a CSV to create a name based mapping of column values
	 *
	 * @param $csvfile
	 *
	 * @return mixed
	 */
	private function mapColumnNames($csvfile)
	{
		$return = new stdClass;
		foreach ($csvfile[0] as $key => $value)
		{
			$return->{$this->camelCase($value)} = $key;
		}

		return $return;
	}

	/**
	 *
	 * @param $string
	 *
	 * @return mixed|string
	 */
	private function camelCase($string)
	{

		// Make sure that all words are upper case, but other letters lower
		$str = ucwords(strtolower($string));

		// Remove any duplicate whitespace, and ensure all characters are alphanumeric
		$str = preg_replace('/[^A-Za-z0-9]/', '', $str);

		// Trim whitespace and lower case first String
		$str = trim(lcfirst($str));

		return $str;
	}

	/**
	 * Read a CSV file and return it as a multidimensional array
	 *
	 * @return array
	 */
	public function readCSVFile($fileName)
	{
		return array_map('str_getcsv', file($fileName));
	}

	/**
	 * Saves each non-duplicated item as a Joomla article
	 *
	 * @param $xml
	 * @param $catId
	 */
	private function save($item, $catId)
	{

		// The item being imported is not a duplicate
		if (!$this->isDuplicate($item->guid))
		{

			if (strpos($item->description, 'class="read-more"'))
			{
				$item->description = $this->getFullText($item->guid);
			}

			$article = JTable::getInstance('content', 'JTable');
			$creator = $item->children('dc', true);
			$date    = JFactory::getDate($item->pubDate);

			$article->access           = 1;
			$article->alias            = JFilterOutput::stringURLSafe($item->title);
			$article->catid            = $catId;
			$article->created          = $date->toSQL();
			$article->created_by       = $this->getAdminId();
			$article->created_by_alias = (string) $creator;
			$article->introtext        = (string) $item->description;
			$article->language         = '*';
			$article->metadata         = '{"robots":"","author":"","rights":"","xreference":"","tags":null}';
			$article->publish_up       = JFactory::getDate()->toSql();
			$article->publish_down     = $this->db->getNullDate();
			$article->state            = 1;
			$article->title            = (string) $item->title[0];
			$article->xreference       = (string) $item->guid;

			try
			{
				$article->check();
			} catch (RuntimeException $e)
			{
				$this->out($e->getMessage(), true);
				$this->close($e->getCode());
			}
			try
			{
				$article->store(true);
			} catch (RuntimeException $e)
			{
				$this->out($e->getMessage(), true);
				$this->close($e->getCode());
			}

			if ($this->input->get('v'))
			{
				$this->out(JFactory::getDate('now')->toSQL() . ': Saved new article "' . $article->title . '", WordPress post ID ' . $this->postId($item) . ', article ID ' . $article->id);
			}
		}
	}
}

// Instantiate the application object, passing the class name to JCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('ImportwordpressCli')->execute();