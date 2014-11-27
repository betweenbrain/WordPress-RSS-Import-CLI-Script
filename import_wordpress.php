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
		$xml = $this->getFeed('http://www.winkworth.co.uk/property-blog/feed/');
		$this->saveItems($xml, 2);
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

	private function getFeed($url)
	{
		$curl = curl_init();
		curl_setopt_array($curl, Array(
			CURLOPT_URL            => $url,
			CURLOPT_USERAGENT      => 'spider',
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING       => 'UTF-8'
		));
		$data = curl_exec($curl);
		curl_close($curl);
		$xml = simplexml_load_string($data, 'SimpleXMLElement', LIBXML_NOCDATA);

		return $xml;
	}

	private function saveItems($xml, $catId)
	{
		$query = $this->db->getQuery(true);
		$query
			->select($this->db->quoteName('title'))
			->from($this->db->quoteName('#__content'))
			->where(
				$this->db->quoteName('catid') . ' = ' . $catId . ' AND ' .
				$this->db->quoteName('state') . ' = 1');
		$this->db->setQuery($query);
		$articles = $this->db->loadObjectList();

		foreach ($xml->channel->item as $item)
		{

			$duplicate = false;

			// Check for duplicates between those being imported and those already saved
			foreach ($articles as $article)
			{
				if ($article->title == $item->title)
				{
					$duplicate = true;
				}
			}

			// The item being imported is not a duplicate
			if (!$duplicate)
			{

				$this->out('Processing ' . (string) $item->title);

				$table = JTable::getInstance('content', 'JTable');

				//return print_r($item, true);

				$creator = $item->children('dc', true);
				$date    = JFactory::getDate($item->pubDate);

				$article = array(
					'access'           => 1,
					'alias'            => JFilterOutput::stringURLSafe($item->title),
					'catid'            => $catId,
					'created'          => $date->toSQL(),
					'created_by'       => $this->getAdminId(),
					'created_by_alias' => (string) $creator,
					'introtext'        => (string) $item->description,
					'language'         => '*',
					'metadata'         => '{"robots":"","author":"","rights":"","xreference":"","tags":null}',
					'publish_up'       => JFactory::getDate()->toSql(),
					'publish_down'     => $this->db->getNullDate(),
					'state'            => 1,
					'title'            => (string) $item->title[0],
					'version'          => 1
				);

				try
				{
					$this->out('Saving ' . $article['title'], true);

					$table->save($article);
				} catch (RuntimeException $e)
				{
					$this->out('Saving ' . $article['title'] . ' failed', true);

					$this->out($e->getMessage(), true);
					$this->close($e->getCode());
				}

				$this->out('Saving ' . $article['title'] . ' done', true);
			}
		}
	}
}

// Instantiate the application object, passing the class name to JCli::getInstance
// and use chaining to execute the application.
JApplicationCli::getInstance('ImportwordpressCli')->execute();