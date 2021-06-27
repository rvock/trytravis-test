<?php
declare(strict_types = 1);

namespace Vierwd\VierwdBase\Tests\Unit\Backend;

use Nimut\TestingFramework\TestCase\UnitTestCase;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;

use Vierwd\VierwdBase\Resource\FilterFiles;

class FilterFilesTest extends UnitTestCase {

	/**
	 * @dataProvider getSanitizeFileNameTestData
	 * @param bool|int $expected
	 */
	public function testFilterFilesCallback($expected, string $itemName, string $itemIdentifier): void {
		$driverInstance = $this->getMockBuilder(DriverInterface::class)
			->disableOriginalConstructor()
			->getMock();
		$this->assertEquals($expected, FilterFiles::filterFilesCallback($itemName, $itemIdentifier, dirname($itemIdentifier), [], $driverInstance));
	}

	public function getSanitizeFileNameTestData(): array {
		return [
			[-1, '.svn', '/.svn/'],
			[-1, '.git', '/.git/'],
			[-1, 'Thumbs.db', '/subfolder/Thumbs.db'],
			[-1, 'Thumbs.db', '/Thumbs.db'],
			[-1, '.DS_Store', '/.DS_Store'],
			[-1, '.DS_Store', '/subfolder/.DS_Store'],
			[-1, '.ds_store', '/case-matters/.ds_store'],
			[-1, 'file-in-svn-folder', '/subdir/.svn/file-in-svn-folder'],
			[-1, '.svn-base', '/file-with-extension/example.svn-base'],
			[-1, '_vti_example', '/file-with-prefix/_vti_example'],
			[true, 'valid-file', '/subdir/valid-file'],
		];
	}
}