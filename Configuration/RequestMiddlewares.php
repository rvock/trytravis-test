<?php

$vierwdMiddlewares = [
	'frontend' => [
		'vierwd/base/404-before-site-redirect' => [
			'target' => \Vierwd\VierwdBase\Frontend\Middleware\PageNotFoundBeforeSiteRedirect::class,
			'before' => [
				'typo3/cms-frontend/base-redirect-resolver',
			],
		],
		'vierwd/base/language-get-var' => [
			'target' => \Vierwd\VierwdBase\Frontend\Middleware\LanguageGetVar::class,
			'after' => [
				'typo3/cms-frontend/site',
			],
			'before' => [
				'vierwd/base/404-before-site-redirect',
				'typo3/cms-frontend/page-resolver',
			],
		],
		'vierwd/base/typo3-version' => [
			'target' => \Vierwd\VierwdBase\Frontend\Middleware\Typo3Version::class,
			'before' => [
				'typo3/cms-frontend/eid',
			],
		],
	],
];

if (!empty($_SERVER['VIERWD_CONFIG'])) {
	$vierwdMiddlewares['frontend']['vierwd/base/browser-sync'] = [
		'target' => \Vierwd\VierwdBase\Frontend\Middleware\BrowserSync::class,
		'after' => [
			'typo3/cms-frontend/content-length-headers',
		],
		'before' => [
			'typo3/cms-frontend/output-compression',
		],
	];
}

return $vierwdMiddlewares;
