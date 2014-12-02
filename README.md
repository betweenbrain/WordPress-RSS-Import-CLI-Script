## WordPress-RSS-Import-CLI-Script

A Joomla CLI script to import WordPress RSS feeds as articles.

### Configuration

Set `$this->csvUrl` to the CSV file URL.

To configure a Google Drive Spreadsheet for automatic fetching as a CSV file, see [CSV Auto Fetch using Google Drive Spreadsheet](https://aftership.uservoice.com/knowledgebase/articles/331269-csv-auto-fetch-using-google-drive-spreadsheet)

### Usage

Clone this repo, or manually add `import-wordpress.php`, to your Joomla site's `cli` directory.

Used as `php import-wordpress.php -v`

Where
 
 * `-v` [optional] verbose output of script profiling and creation of new articles.
