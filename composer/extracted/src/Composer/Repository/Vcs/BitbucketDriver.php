<?php











namespace Composer\Repository\Vcs;

use Composer\Cache;
use Composer\Downloader\TransportException;
use Composer\Json\JsonFile;
use Composer\Util\Bitbucket;

abstract class BitbucketDriver extends VcsDriver
{

protected $cache;
protected $owner;
protected $repository;
protected $hasIssues;
protected $rootIdentifier;
protected $tags;
protected $branches;
protected $infoCache = array();
protected $branchesUrl = '';
protected $tagsUrl = '';
protected $homeUrl = '';
protected $website = '';
protected $cloneHttpsUrl = '';




protected $fallbackDriver;

protected $vcsType;




public function initialize()
{
preg_match('#^https?://bitbucket\.org/([^/]+)/([^/]+?)(\.git|/?)$#', $this->url, $match);
$this->owner = $match[1];
$this->repository = $match[2];
$this->originUrl = 'bitbucket.org';
$this->cache = new Cache(
$this->io,
implode('/', array(
$this->config->get('cache-repo-dir'),
$this->originUrl,
$this->owner,
$this->repository,
))
);
}




public function getUrl()
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getUrl();
}

return $this->cloneHttpsUrl;
}







protected function getRepoData()
{
$resource = sprintf(
'https://api.bitbucket.org/2.0/repositories/%s/%s?%s',
$this->owner,
$this->repository,
http_build_query(
array('fields' => '-project,-owner'),
null,
'&'
)
);

$repoData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource, true), $resource);
if ($this->fallbackDriver) {
return false;
}
$this->parseCloneUrls($repoData['links']['clone']);

$this->hasIssues = !empty($repoData['has_issues']);
$this->branchesUrl = $repoData['links']['branches']['href'];
$this->tagsUrl = $repoData['links']['tags']['href'];
$this->homeUrl = $repoData['links']['html']['href'];
$this->website = $repoData['website'];
$this->vcsType = $repoData['scm'];

return true;
}




public function getComposerInformation($identifier)
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getComposerInformation($identifier);
}

if (!isset($this->infoCache[$identifier])) {
if ($this->shouldCache($identifier) && $res = $this->cache->read($identifier)) {
return $this->infoCache[$identifier] = JsonFile::parseJson($res);
}

$composer = $this->getBaseComposerInformation($identifier);


 if (!isset($composer['support']['source'])) {
$label = array_search(
$identifier,
$this->getTags()
) ?: array_search(
$identifier,
$this->getBranches()
) ?: $identifier;

if (array_key_exists($label, $tags = $this->getTags())) {
$hash = $tags[$label];
} elseif (array_key_exists($label, $branches = $this->getBranches())) {
$hash = $branches[$label];
}

if (! isset($hash)) {
$composer['support']['source'] = sprintf(
'https://%s/%s/%s/src',
$this->originUrl,
$this->owner,
$this->repository
);
} else {
$composer['support']['source'] = sprintf(
'https://%s/%s/%s/src/%s/?at=%s',
$this->originUrl,
$this->owner,
$this->repository,
$hash,
$label
);
}
}
if (!isset($composer['support']['issues']) && $this->hasIssues) {
$composer['support']['issues'] = sprintf(
'https://%s/%s/%s/issues',
$this->originUrl,
$this->owner,
$this->repository
);
}
if (!isset($composer['homepage'])) {
$composer['homepage'] = empty($this->website) ? $this->homeUrl : $this->website;
}

$this->infoCache[$identifier] = $composer;

if ($this->shouldCache($identifier)) {
$this->cache->write($identifier, json_encode($composer));
}
}

return $this->infoCache[$identifier];
}




public function getFileContent($file, $identifier)
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getFileContent($file, $identifier);
}

$resource = sprintf(
'https://api.bitbucket.org/1.0/repositories/%s/%s/raw/%s/%s',
$this->owner,
$this->repository,
$identifier,
$file
);

return $this->getContentsWithOAuthCredentials($resource);
}




public function getChangeDate($identifier)
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getChangeDate($identifier);
}

$resource = sprintf(
'https://api.bitbucket.org/2.0/repositories/%s/%s/commit/%s?fields=date',
$this->owner,
$this->repository,
$identifier
);
$commit = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);

return new \DateTime($commit['date']);
}




public function getSource($identifier)
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getSource($identifier);
}

return array('type' => $this->vcsType, 'url' => $this->getUrl(), 'reference' => $identifier);
}




public function getDist($identifier)
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getDist($identifier);
}

$url = sprintf(
'https://bitbucket.org/%s/%s/get/%s.zip',
$this->owner,
$this->repository,
$identifier
);

return array('type' => 'zip', 'url' => $url, 'reference' => $identifier, 'shasum' => '');
}




public function getTags()
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getTags();
}

if (null === $this->tags) {
$this->tags = array();
$resource = sprintf(
'%s?%s',
$this->tagsUrl,
http_build_query(
array(
'pagelen' => 100,
'fields' => 'values.name,values.target.hash,next',
'sort' => '-target.date',
),
null,
'&'
)
);
$hasNext = true;
while ($hasNext) {
$tagsData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
foreach ($tagsData['values'] as $data) {
$this->tags[$data['name']] = $data['target']['hash'];
}
if (empty($tagsData['next'])) {
$hasNext = false;
} else {
$resource = $tagsData['next'];
}
}
if ($this->vcsType === 'hg') {
unset($this->tags['tip']);
}
}

return $this->tags;
}




public function getBranches()
{
if ($this->fallbackDriver) {
return $this->fallbackDriver->getBranches();
}

if (null === $this->branches) {
$this->branches = array();
$resource = sprintf(
'%s?%s',
$this->branchesUrl,
http_build_query(
array(
'pagelen' => 100,
'fields' => 'values.name,values.target.hash,next',
'sort' => '-target.date',
),
null,
'&'
)
);
$hasNext = true;
while ($hasNext) {
$branchData = JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
foreach ($branchData['values'] as $data) {
$this->branches[$data['name']] = $data['target']['hash'];
}
if (empty($branchData['next'])) {
$hasNext = false;
} else {
$resource = $branchData['next'];
}
}
}

return $this->branches;
}









protected function getContentsWithOAuthCredentials($url, $fetchingRepoData = false)
{
try {
return parent::getContents($url);
} catch (TransportException $e) {
$bitbucketUtil = new Bitbucket($this->io, $this->config, $this->process, $this->remoteFilesystem);

if (403 === $e->getCode() || (401 === $e->getCode() && strpos($e->getMessage(), 'Could not authenticate against') === 0)) {
if (!$this->io->hasAuthentication($this->originUrl)
&& $bitbucketUtil->authorizeOAuth($this->originUrl)
) {
return parent::getContents($url);
}

if (!$this->io->isInteractive() && $fetchingRepoData) {
return $this->attemptCloneFallback();
}
}

throw $e;
}
}






abstract protected function generateSshUrl();

protected function attemptCloneFallback()
{
try {
$this->setupFallbackDriver($this->generateSshUrl());
} catch (\RuntimeException $e) {
$this->fallbackDriver = null;

$this->io->writeError(
'<error>Failed to clone the ' . $this->generateSshUrl() . ' repository, try running in interactive mode'
. ' so that you can enter your Bitbucket OAuth consumer credentials</error>'
);
throw $e;
}
}





abstract protected function setupFallbackDriver($url);





protected function parseCloneUrls(array $cloneLinks)
{
foreach ($cloneLinks as $cloneLink) {
if ($cloneLink['name'] === 'https') {

 
 $this->cloneHttpsUrl = preg_replace('/https:\/\/([^@]+@)?/', 'https://', $cloneLink['href']);
}
}
}




protected function getMainBranchData()
{
$resource = sprintf(
'https://api.bitbucket.org/1.0/repositories/%s/%s/main-branch',
$this->owner,
$this->repository
);

return JsonFile::parseJson($this->getContentsWithOAuthCredentials($resource), $resource);
}
}
