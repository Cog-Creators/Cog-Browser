<?php
declare(strict_types=1);

function getOrThrow(array $data, string $key) : mixed {
	if (isset($data[$key])) {
		return $data[$key];
	}
	throw new ValueError('"' . $key . '" key not available in the array.');
}

function getWithVerifiedType(array $data, string $key) : mixed {
	if (isset($data[$key])) {
		return $data[$key];
	}
	throw new ValueError('"' . $key . '" key not available in the array.');
}

function getString(array $data, string $key, string $default = '') : string {
	if (isset($data[$key]) && is_string($data[$key])) {
		return $data[$key];
	}
	return $default;
}

function getBool(array $data, string $key, bool $default = false) : bool {
	if (isset($data[$key]) && is_bool($data[$key])) {
		return $data[$key];
	}
	return $default;
}

function getArrayOfStrings(array $data, string $key) : array {
	$output = [];
	if (
		isset($data[$key])
		&& is_array($data[$key])
		&& array_key_first($data[$key]) === 0  // verify that the array is a list
	) {
		foreach ($data[$key] as $value) {
			if (is_string($value)) {
				$output[] = $value;
			}
		}
	}
	return $output;
}


enum RepoCategory : string
{
	case Approved = 'approved';
	case Unapproved = 'unapproved';
}


enum InstallableType : string
{
	case Cog = 'COG';
	case SharedLibrary = 'SHARED_LIBRARY';
	case Unknown = 'UNKNOWN';
}


class Repo
{
	public function __construct(
		public string $url,
		public string $name,
		public RepoCategory $category,
		public string $branch = '',
		public array $cogs = [],
	) {}

	public static function fromArray(string $url, array $data): static {
		$category = RepoCategory::from($data['rx_category'] ?? '');  // may throw ValueError
		try {
			$repo = new static(
				url: $url,
				name: getOrThrow($data, 'name'),
				category: $category,
				branch: $data['rx_branch'] ?? '',
			);
		} catch (TypeError) {
			throw new ValueError('Expected ');
		}
		foreach ($data['rx_cogs'] ?? [] as $cogName => $cogData) {
			$repo->cogs[] = Cog::fromArray($repo, $cogName, $cogData);
		}
		return $repo;
	}
}

class Cog
{
	public string $minBotVersion = '';
	public string $maxBotVersion = '';
	public array $tags = [];
	private array $allRequirements = [];

	public function __construct(
		/** Cog name. */
		public string $name,
		public Repo $repo,
		// Optional cog information.
		public InstallableType $type = InstallableType::Cog,
		public array $author = [],
		public string $short = '',
		public string $description = '',
		public string $endUserDataStatement = '',
		string $minBotVersion = '',
		string $maxBotVersion = '',
		public string $minPythonVersion = '',
		public bool $disabled = false,
		public bool $hidden = false,
		public array $requirements = [],
		public array $permissions = [],
		array $tags = [],
	) {
		$this->minBotVersion = $minBotVersion !== '0.0.0' ? $minBotVersion : '';
		$this->maxBotVersion = $maxBotVersion !== '0.0.0' ? $maxBotVersion : '';
		if ($this->minPythonVersion) {
			$this->allRequirements[] = 'Python>=' . $this->minPythonVersion;
		}
		if($this->minBotVersion === $this->maxBotVersion){
			if ($this->minBotVersion) {
				$this->allRequirements[] = 'Bot==' . $this->minBotVersion;
			}
		} else {
			if ($this->minBotVersion) {
				$this->allRequirements[] = 'Bot>=' . $this->minBotVersion;
			}
			if ($this->maxBotVersion) {
				$this->allRequirements[] = 'Bot<=' . $this->maxBotVersion;
			}
		}
		array_push($this->allRequirements, ...$requirements);
		foreach ($tags as $tagName) {
			$this->tags[] = strtolower($tagName);
		}
	}

	public static function fromArray(Repo $repo, string $name, array $data): static {
		$type = InstallableType::tryFrom(getString($data, 'type') ?: 'COG') ?? InstallableType::UNKNOWN;
		$author = getArrayOfStrings($data, 'author');
		$minPythonVersion = '';
		if (
			isset($data['min_python_version'])
			&& is_array($data['min_python_version'])
			// verify that the array is a list with 3 items
			&& array_key_first($data['min_python_version']) === 0
			&& count($data['min_python_version']) === 3
			// verify that the array contains only integers
			&& count(array_filter($data['min_python_version'], fn($v) => !is_integer($v))) === 0
		) {
			$minPythonVersion = implode('.', $data['min_python_version']);
		}
		return new static(
			name: $name,
			repo: $repo,
			type: $type,
			author: getArrayOfStrings($data, 'author'),
			short: getString($data, 'short'),
			description: getString($data, 'description'),
			endUserDataStatement: getString($data, 'end_user_data_statement'),
			minBotVersion: getString($data, 'min_bot_version'),
			maxBotVersion: getString($data, 'max_bot_version'),
			minPythonVersion: $minPythonVersion,
			disabled: getBool($data, 'disabled'),
			hidden: getBool($data, 'hidden'),
			requirements: getArrayOfStrings($data, 'requirements'),
			permissions: getArrayOfStrings($data, 'permissions'),
			tags: getArrayOfStrings($data, 'tags'),
		);
	}

	public function getAllRequirements() : array {
		return $this->allRequirements;
	}
}

function getURL(
	?bool $show_ua = null,
	?int $page = null,
	?string $filter_tag = null,
	?string $search = null,
) : string {
	$params = [];
	$show_ua ??= $GLOBALS['show_ua'];
	if ($show_ua) {
		$params['ua'] = '1';
	}
	$page ??= $GLOBALS['page'];
	if ($page > 1) {
		$params['p'] = strval($page);
	}
	$filter_tag ??= $GLOBALS['filter_tag'];
	if ($filter_tag) {
		$params['filter_tag'] = $filter_tag;
	}
	$search ??= $GLOBALS['search'];
	if ($search) {
		$params['search'] = $search;
	}
	return "/?" . http_build_query($params);
}


$per_page = 25;
$show_ua = getString($_GET, 'ua') === '1';
$search = preg_replace('/[^-a-zA-Z0-9 ]/', '', getString($_GET, 'search'));
$filter_tag = strtolower(preg_replace('/[^-a-zA-Z0-9_]/', '', getString($_GET, 'filter_tag')));
$page = intval(preg_replace('/[^0-9]/', '', getString($_GET, 'p'))) ?: 1;

$red_index_url = getenv("RED_INDEX_URL", true);
if (!$red_index_url) {
	$red_index_url = 'https://raw.githubusercontent.com/Cog-Creators/Red-Index/master/index';
}
$json = json_decode(implode(" ", file($red_index_url . '/1-min.json')), TRUE);
$cogs = [];
foreach ($json as $source => $sourceData) {
	$repo_url = explode('@', $source, 2)[0];
	try {
		$repo = Repo::fromArray($repo_url, $sourceData);
	} catch (ValueError) {
		continue;
	}
	if ($repo->category === RepoCategory::Unapproved && !$show_ua) {
		continue;
	}
	foreach($repo->cogs as $cog){
		if ($cog->hidden || $cog->disabled) {
			continue;
		}
		if ($filter_tag && !in_array($filter_tag, $cog->tags)) {
			continue;
		}
		if ($search) {
			$matched = match (true) {
				stripos($cog->name, $search) !== false,
				stripos($cog->description ?: $cog->short, $search) !== false,
				in_array(strtolower($search), $cog->tags),
					=> true,
				default => false,
			};
			if (!$matched) {
				foreach ($cog->author as $authorName) {
					if (stripos($authorName, $search) !== false) {
						$matched = true;
						break;
					}
				}
			}
			if (!$matched) {
				foreach($cog->getAllRequirements() as $req) {
					if (stripos($req, $search) !== false) {
						$matched = true;
						break;
					}
				}
			}
			if (!$matched) {
				continue;
			}
		}
		array_push($cogs, $cog);
	}
}

usort($cogs, fn($a, $b) => strnatcasecmp($a->name, $b->name));
$cog_chunks = array_chunk($cogs, $per_page);

?>

<html>
	<head>
		<title>Red Discord Bot - Cog Index</title>
		<link href="https://fonts.googleapis.com/css2?family=Merienda&family=Roboto:wght@500&family=Space+Mono&display=swap" rel="stylesheet">
		<link rel="stylesheet" type="text/css" href="assets/style.mini.css?<?= microtime(TRUE) ?>">
		<meta name="viewport" content="width=device-width, initial-scale=1">
	</head>
	<body model="">
		<div class="nav top">
			<a id="logo" href="/">Red<t>Discord Bot - Cog Index</t></a>
			<a class="nav-link" href="https://docs.discord.red/en/stable/install_guides/index.html">Installation</a>
			<a class="nav-link" href="https://docs.discord.red/en/stable/index.html">Documentation</a>
			<a class="nav-link" href="https://discord.gg/red">Join Discord</a>
			<a class="nav-link" href="https://docs.discord.red/en/stable/guide_cog_creation.html">Build Your Own Cog</a>
		</div>
		<div class="search">
			<form id="search" method="get">
				<svg class="icon" viewBox="0 0 20 20">
					<path d="M12.323,2.398c-0.741-0.312-1.523-0.472-2.319-0.472c-2.394,0-4.544,1.423-5.476,3.625C3.907,7.013,3.896,8.629,4.49,10.102c0.528,1.304,1.494,2.333,2.72,2.99L5.467,17.33c-0.113,0.273,0.018,0.59,0.292,0.703c0.068,0.027,0.137,0.041,0.206,0.041c0.211,0,0.412-0.127,0.498-0.334l1.74-4.23c0.583,0.186,1.18,0.309,1.795,0.309c2.394,0,4.544-1.424,5.478-3.629C16.755,7.173,15.342,3.68,12.323,2.398z M14.488,9.77c-0.769,1.807-2.529,2.975-4.49,2.975c-0.651,0-1.291-0.131-1.897-0.387c-0.002-0.004-0.002-0.004-0.002-0.004c-0.003,0-0.003,0-0.003,0s0,0,0,0c-1.195-0.508-2.121-1.452-2.607-2.656c-0.489-1.205-0.477-2.53,0.03-3.727c0.764-1.805,2.525-2.969,4.487-2.969c0.651,0,1.292,0.129,1.898,0.386C14.374,4.438,15.533,7.3,14.488,9.77z"></path>
				</svg>
				<input type="text" name="search" placeholder="Search <?= count($cogs) . ($filter_tag ? " $filter_tag" : '') ?> cogs..." value="<?= $search ?>" />
				<?php
				if ($show_ua) {
				?>
				<input type="hidden" name="ua" value="1" />
				<?php
				}
				?>
				<?php
				if ($filter_tag) {
				?>
				<input type="hidden" name="filter_tag" value="<?= $filter_tag ?>" />
				<?php
				}
				?>
				<button type="submit">
					<svg class="icon" viewBox="0 0 20 20">
						<path d="M14.989,9.491L6.071,0.537C5.78,0.246,5.308,0.244,5.017,0.535c-0.294,0.29-0.294,0.763-0.003,1.054l8.394,8.428L5.014,18.41c-0.291,0.291-0.291,0.763,0,1.054c0.146,0.146,0.335,0.218,0.527,0.218c0.19,0,0.382-0.073,0.527-0.218l8.918-8.919C15.277,10.254,15.277,9.784,14.989,9.491z"></path>
					</svg>
				</button>
			</form>
		</div>
		<?php if ($show_ua) { ?>
			<div class="ua-warning">The content of unapproved repositories has not been vetted by QA<br>Safety is not guaranteed. Use at your own risk</div>
		<?php } ?>
		<div class="filters">
			<box <?php if ($show_ua) { ?>href="<?= getURL(show_ua: false) ?>"<?php } else {?>show-model="uadisclaim"<?php } ?>><svg class="icon" viewBox="0 0 20 20">
				<?php if ($show_ua) { ?>
					<path fill-rule="evenodd" d="M2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2zm10.03 4.97a.75.75 0 0 0-1.08.022L7.477 9.417 5.384 7.323a.75.75 0 0 0-1.06 1.06L6.97 11.03a.75.75 0 0 0 1.079-.02l3.992-4.99a.75.75 0 0 0-.01-1.05z"></path>
				<?php } else { ?>
					<path fill-rule="evenodd" d="M14 1H2a1 1 0 0 0-1 1v12a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V2a1 1 0 0 0-1-1zM2 0a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2H2z"/>
				<?php } ?>
			</svg>Include Unapproved</box>
		</div>
		<div class="list">
			<div class="model" model="uadisclaim">
				<h><svg class="icon" viewBox="0 0 20 23">
					<path d="M18.344,16.174l-7.98-12.856c-0.172-0.288-0.586-0.288-0.758,0L1.627,16.217c0.339-0.543-0.603,0.668,0.384,0.682h15.991C18.893,16.891,18.167,15.961,18.344,16.174 M2.789,16.008l7.196-11.6l7.224,11.6H2.789z M10.455,7.552v3.561c0,0.244-0.199,0.445-0.443,0.445s-0.443-0.201-0.443-0.445V7.552c0-0.245,0.199-0.445,0.443-0.445S10.455,7.307,10.455,7.552M10.012,12.439c-0.733,0-1.33,0.6-1.33,1.336s0.597,1.336,1.33,1.336c0.734,0,1.33-0.6,1.33-1.336S10.746,12.439,10.012,12.439M10.012,14.221c-0.244,0-0.443-0.199-0.443-0.445c0-0.244,0.199-0.445,0.443-0.445s0.443,0.201,0.443,0.445C10.455,14.021,10.256,14.221,10.012,14.221"></path></svg>Warning: Use at your own risk!</h>
				<t>Unapproved repositories are provided by the community and have not yet been inspected for security or tested for stability. The Cog-Creators organization and Red's contributors are not responsible for any damage caused by 3rd party cogs.</t>
				<f><button hide-model="true">Nevermind</button><button class="right red" href="<?= getURL(show_ua: true) ?>">I understand and accept the risks</button></f>
			</div>
			<?php if(!isset($cog_chunks[$page - 1])){ ?>
				<div>
					<t><center>There are no cogs on this page.</center></t>
				</div>
			<?php }else{ foreach($cog_chunks[$page - 1] as $cog){ ?>
				<div>
					<h>
						<?= $cog->name ?><tag class="approval<?php if ($cog->repo->category === RepoCategory::Approved) { ?> active<?php } ?>"><?= $cog->repo->category->name ?></tag>
						<authors>
							<?php foreach($cog->author as $authorName){?><tag>@<?= $authorName ?></tag><?php }?>
						</authors>
					</h>
					<t><i><?= nl2br(htmlentities($cog->description ?: $cog->short)) ?></i></t>
					<?php if ($cog->endUserDataStatement) { ?>
						<t><svg class="icon" viewBox="0 0 20 20">
							<path d="M10,6.978c-1.666,0-3.022,1.356-3.022,3.022S8.334,13.022,10,13.022s3.022-1.356,3.022-3.022S11.666,6.978,10,6.978M10,12.267c-1.25,0-2.267-1.017-2.267-2.267c0-1.25,1.016-2.267,2.267-2.267c1.251,0,2.267,1.016,2.267,2.267C12.267,11.25,11.251,12.267,10,12.267 M18.391,9.733l-1.624-1.639C14.966,6.279,12.563,5.278,10,5.278S5.034,6.279,3.234,8.094L1.609,9.733c-0.146,0.147-0.146,0.386,0,0.533l1.625,1.639c1.8,1.815,4.203,2.816,6.766,2.816s4.966-1.001,6.767-2.816l1.624-1.639C18.536,10.119,18.536,9.881,18.391,9.733 M16.229,11.373c-1.656,1.672-3.868,2.594-6.229,2.594s-4.573-0.922-6.23-2.594L2.41,10l1.36-1.374C5.427,6.955,7.639,6.033,10,6.033s4.573,0.922,6.229,2.593L17.59,10L16.229,11.373z"></path> </svg><?= nl2br(htmlentities($cog->endUserDataStatement)) ?></t>
					<?php } ?>
					<?php if ($cog->permissions) { ?>
						<t><svg class="icon" viewBox="0 0 20 20">
							<path d="M12.546,4.6h-5.2C4.398,4.6,2,7.022,2,10c0,2.978,2.398,5.4,5.346,5.4h5.2C15.552,15.4,18,12.978,18,10C18,7.022,15.552,4.6,12.546,4.6 M12.546,14.6h-5.2C4.838,14.6,2.8,12.536,2.8,10s2.038-4.6,4.546-4.6h5.2c2.522,0,4.654,2.106,4.654,4.6S15.068,14.6,12.546,14.6 M12.562,6.2C10.488,6.2,8.8,7.904,8.8,10c0,2.096,1.688,3.8,3.763,3.8c2.115,0,3.838-1.706,3.838-3.8C16.4,7.904,14.678,6.2,12.562,6.2 M12.562,13C10.93,13,9.6,11.654,9.6,10c0-1.654,1.33-3,2.962-3C14.21,7,15.6,8.374,15.6,10S14.208,13,12.562,13"></path></svg><b>Required Permissions</b><l><?= implode(' ', $cog->permissions) ?></l></t>
					<?php } ?>
					<?php if ($cog->getAllRequirements()) { ?>
						<t><svg class="icon" viewBox="0 0 20 23">
							<path d="M18.344,16.174l-7.98-12.856c-0.172-0.288-0.586-0.288-0.758,0L1.627,16.217c0.339-0.543-0.603,0.668,0.384,0.682h15.991C18.893,16.891,18.167,15.961,18.344,16.174 M2.789,16.008l7.196-11.6l7.224,11.6H2.789z M10.455,7.552v3.561c0,0.244-0.199,0.445-0.443,0.445s-0.443-0.201-0.443-0.445V7.552c0-0.245,0.199-0.445,0.443-0.445S10.455,7.307,10.455,7.552M10.012,12.439c-0.733,0-1.33,0.6-1.33,1.336s0.597,1.336,1.33,1.336c0.734,0,1.33-0.6,1.33-1.336S10.746,12.439,10.012,12.439M10.012,14.221c-0.244,0-0.443-0.199-0.443-0.445c0-0.244,0.199-0.445,0.443-0.445s0.443,0.201,0.443,0.445C10.455,14.021,10.256,14.221,10.012,14.221"></path></svg><b>Bot Version and Packages Used</b><l><?= implode('</l><l>', $cog->getAllRequirements()) ?></l></t>
					<?php } ?>

					<t><svg class="icon" viewBox="0 0 16 19">
						<path fill-rule="evenodd" d="M0 6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V6zm13 .25a.25.25 0 0 1 .25-.25h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5a.25.25 0 0 1-.25-.25v-.5zM2.25 8a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5A.25.25 0 0 0 3 8.75v-.5A.25.25 0 0 0 2.75 8h-.5zM4 8.25A.25.25 0 0 1 4.25 8h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5A.25.25 0 0 1 4 8.75v-.5zM6.25 8a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5A.25.25 0 0 0 7 8.75v-.5A.25.25 0 0 0 6.75 8h-.5zM8 8.25A.25.25 0 0 1 8.25 8h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5A.25.25 0 0 1 8 8.75v-.5zM13.25 8a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5a.25.25 0 0 0 .25-.25v-.5a.25.25 0 0 0-.25-.25h-.5zm0 2a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5a.25.25 0 0 0 .25-.25v-.5a.25.25 0 0 0-.25-.25h-.5zm-3-2a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h1.5a.25.25 0 0 0 .25-.25v-.5a.25.25 0 0 0-.25-.25h-1.5zm.75 2.25a.25.25 0 0 1 .25-.25h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5a.25.25 0 0 1-.25-.25v-.5zM11.25 6a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5a.25.25 0 0 0 .25-.25v-.5a.25.25 0 0 0-.25-.25h-.5zM9 6.25A.25.25 0 0 1 9.25 6h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5A.25.25 0 0 1 9 6.75v-.5zM7.25 6a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h.5A.25.25 0 0 0 8 6.75v-.5A.25.25 0 0 0 7.75 6h-.5zM5 6.25A.25.25 0 0 1 5.25 6h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5A.25.25 0 0 1 5 6.75v-.5zM2.25 6a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h1.5A.25.25 0 0 0 4 6.75v-.5A.25.25 0 0 0 3.75 6h-1.5zM2 10.25a.25.25 0 0 1 .25-.25h.5a.25.25 0 0 1 .25.25v.5a.25.25 0 0 1-.25.25h-.5a.25.25 0 0 1-.25-.25v-.5zM4.25 10a.25.25 0 0 0-.25.25v.5c0 .138.112.25.25.25h5.5a.25.25 0 0 0 .25-.25v-.5a.25.25 0 0 0-.25-.25h-5.5z"></path>
					</svg><b>Installation commands</b><l>repo add <?= strtolower($cog->repo->name) ?> <?= $cog->repo->url ?> <?= $cog->repo->branch ?></l><l>cog install <?= strtolower($cog->repo->name) ?> <?= $cog->name ?></l></t>
					<f><?php sort($cog->tags);foreach($cog->tags as $tag){?><tag href="<?= getURL(page: 1, filter_tag: $tag) ?>"><?= $tag ?></tag><?php }?><a class="src-link" href="<?= $cog->repo->url ?>">View Repository</a></f>
				</div>
			<?php }} ?>
		</div>
		<div class="nav bottom">
			<a class="left <?php if ($page <= 1) { ?>hidden<?php } ?>" href="<?= getURL(page: $page - 1) ?>">
				<svg class="icon" viewBox="0 0 20 20">
					<path d="M18.271,9.212H3.615l4.184-4.184c0.306-0.306,0.306-0.801,0-1.107c-0.306-0.306-0.801-0.306-1.107,0
	L1.21,9.403C1.194,9.417,1.174,9.421,1.158,9.437c-0.181,0.181-0.242,0.425-0.209,0.66c0.005,0.038,0.012,0.071,0.022,0.109
	c0.028,0.098,0.075,0.188,0.142,0.271c0.021,0.026,0.021,0.061,0.045,0.085c0.015,0.016,0.034,0.02,0.05,0.033l5.484,5.483
	c0.306,0.307,0.801,0.307,1.107,0c0.306-0.305,0.306-0.801,0-1.105l-4.184-4.185h14.656c0.436,0,0.788-0.353,0.788-0.788
	S18.707,9.212,18.271,9.212z"></path>
				</svg>
				Previous
			</a>
			<?php if (count($cog_chunks) >= 1) { ?>
  <page>Page <?= $page ?> of <?= count($cog_chunks) ?></page>
<?php } ?>

			<a class="right <?php if (count($cog_chunks) <= $page) { ?>hidden<?php } ?>" href="<?= getURL(page: $page + 1) ?>">
				Next
				<svg class="icon" viewBox="0 0 20 20">
					<path d="M1.729,9.212h14.656l-4.184-4.184c-0.307-0.306-0.307-0.801,0-1.107c0.305-0.306,0.801-0.306,1.106,0
	l5.481,5.482c0.018,0.014,0.037,0.019,0.053,0.034c0.181,0.181,0.242,0.425,0.209,0.66c-0.004,0.038-0.012,0.071-0.021,0.109
	c-0.028,0.098-0.075,0.188-0.143,0.271c-0.021,0.026-0.021,0.061-0.045,0.085c-0.015,0.016-0.034,0.02-0.051,0.033l-5.483,5.483
	c-0.306,0.307-0.802,0.307-1.106,0c-0.307-0.305-0.307-0.801,0-1.105l4.184-4.185H1.729c-0.436,0-0.788-0.353-0.788-0.788
	S1.293,9.212,1.729,9.212z"></path>
				</svg>
			</a>
		</div>
		<a href="https://github.com/Cog-Creators/Cog-Browser"><img style="position: absolute; top: 0; right: 0; border: 0;" src="https://github.blog/wp-content/uploads/2008/12/forkme_right_darkblue_121621.png?resize=149%2C149" class="attachment-full size-full" alt="Fork me on GitHub"></a>
		<script>
			document.addEventListener('click', (event) => {
				let match;
				match = event.target.closest('[href]');
				if (match) {
					match.classList.add('loading');
					window.location.href = match.getAttribute('href');
				}
				match = event.target.closest('[show-model]');
				if (match) {
					document.body.setAttribute('model', match.getAttribute('show-model'));
				}
				match = event.target.closest('[hide-model]');
				if (match) {
					document.body.setAttribute('model', '');
				}
			});
		</script>
		<!-- Cloudflare Web Analytics --><script defer src='https://static.cloudflareinsights.com/beacon.min.js' data-cf-beacon='{"token": "42ef8cfa260844718cbcd6eaedd5fb88"}'></script><!-- End Cloudflare Web Analytics -->
	</body>
</html>
