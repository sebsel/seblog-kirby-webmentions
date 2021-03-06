<?php

namespace Kirby\Webmentions;

use Field;
use Collection;
use Dir;
use Url;
use Page;
use Data;
use Str;
use V;
use C;
use Remote;
use Tpl;
use Exception;

// I tried to add this function to the Kirby Toolkit but
// it never got merged, so it's now here as a stand-alone.
function url__solveRelative($base, $path) {
  if(url::isAbsolute($path)) return $path;
  $fragments = url::fragments($path);
  // If the path is not /absolute, take $home in account
  if(!str::startsWith($path, '/')) {
    $pathFragments = $fragments;
    $baseFragments = url::fragments($base);
    // If $base is not a folder, remove the last part
    if(!str::endsWith($base, '/')) array_pop($baseFragments);
    $fragments = $baseFragments;
    foreach($pathFragments as $f) $fragments []= $f;
  }
  $filter = array(
    'hash' => '',
    'query' => '',
    'params' => array(),
    'fragments' => $fragments,
  );
  return url::build($filter, $base);
}

class Mentions extends Collection {

  public $options  = array();
  public $page     = null;
  public $root     = null;
  public $headline = null;

  public function __construct($params = array()) {

    $defaults = array(
      'page'     => page(),
      'headline' => 'Mentions'
    );

    if(is_a($params, 'Page')) {
      $params = array('page' => $params);
    } else if(is_string($params)) {
      $params = array('headline' => $params);
    }

    $this->options  = array_merge($defaults, $params);
    $this->page     = $this->options['page'];
    $this->root     = $this->page->root() . DS . '.webmentions';
    $this->headline = new Field($this->page, 'headline', $this->options['headline']);

    if(!is_dir($this->root)) return;

    $files = dir::read($this->root);

    // flip direction
    if(c::get('webmentions.reverse-order', false)) rsort($files);

    foreach($files as $file) {

      // skip the pings cache
      if($file == 'pings.json') continue;

      // register a new webmention

      try {
        $mention = new Mention($this->page, $this->root . DS . $file);
        $this->append($mention->id(), $mention);
      } catch(Exception $e) {

      }

    }

  }

  public function ping() {

    // check for an existing ping cache
    $cache = $this->root . DS . 'pings.json';

    if(file_exists($cache)) return;

    // Create the cache already, so we don't trigger ourselfs recursively
    data::write($cache, []);

    // The Regular Expression filter
    $expression = "/(https?|ftps?)\:\/\/[a-zA-Z0-9\-\.]+\.[a-zA-Z]{2,}(\/[^>,\")\s]*)?/";
    $triggered  = [];
    $logs       = [];

    $searchfield = "";

    foreach (c::get('webmentions.fields', ['text']) as $field) {
      $searchfield .= " ".$this->page->content()->get($field);
    }

    // Check if there is a url in the text
    if(preg_match_all($expression, (string)$searchfield, $urls)) {
      foreach($urls[0] as $url) {
        if(!in_array($url, $triggered)) {

          if(str::startsWith($url, 'https://brid.gy/publish')) {
            $archive = [];
          } else {
            $r = remote::get('http://web.archive.org/save/'.$url);

            $archive = [
              'archive' => (isset($r->headers['Content-Location']) ? 'http://web.archive.org' . $r->headers['Content-Location'] : false)
            ];
          }

          if($log = $this->trigger($url)) {
            $logs[] = $log + $archive;
          } else {
            $logs[] = ['url' => $url, 'endpoint' => false] + $archive;
          }
          $triggered[] = $url;
        }
      }
    }

    data::write($cache, $logs);

  }

  public function trigger($url) {

    if ($endpoint = static::discoverEndpoint($url)) {

      $src      = $this->page->url();
      $target   = $url;

      $r = remote::post($endpoint, array(
        'data' => array(
          'source' => $src,
          'target' => $target
        )
      ));

      if (str::startsWith($url, 'https://brid.gy/publish') and $r->code == 201) {
        $this->page->update([
          'syndicate_to' => null,
          'syndication' => $r->headers['Location']
        ]);
      }

      return [
        'url' => $url,
        'endpoint' => $endpoint,
        'code' => $r->code,
        'location' => isset($r->headers['Location']) ? $r->headers['Location'] : null,
      ];

    } else {
      return false;
    }
  }

  static public function discoverEndpoint($url) {

    $response = remote::get($url);
    $url      = $response->info['url'];
    $html     = $response->content();
    $headers  = $response->headers();

    $headers = array_change_key_case($headers);

    if(isset($headers['link'])) {

      foreach(explode(',', $headers['link']) as $link) {

        if(preg_match('!\<(.*?)\>;\s*rel="?(.*?\s?)webmention(\s?.*?)"?!', $link, $match)) {

          $endpoint = url__solveRelative($match[1], $url);

          // return valid endpoint or continue searching
          if(v::url($endpoint)) {
            return $endpoint;
          }
        }
      }

    }

    if(preg_match_all('!\<(a|link)(.*?)\>!i', $html, $links)) {

      foreach($links[0] as $link) {

        if(!preg_match('!rel="(.*?\s)?webmention(\s.*?)?"!', $link)) {
          continue;
        }

        if(!preg_match('!href="(.*?)"!', $link, $match)) {
          continue;
        }

        if($match[1] == "") {
          return $url;
        }

        $endpoint = url__solveRelative($match[1], $url);

        // invalid endpoint
        if(!v::url($endpoint)) {
          continue;
        }

        return $endpoint;

      }

    }

    return false;
  }


  public function toHtml() {

    $snippet = kirby()->roots()->snippets() . DS . 'webmentions' . DS . 'mentions.php';

    if (!file_exists($snippet)) {
      $snippet = dirname(__DIR__) . DS . 'snippets' . DS . 'mentions.php';
    }

    return tpl::load($snippet, array(
      'mentions'  => $this,
      'rsvps'     => $this->filterBy('type', 'rsvp'),
      'likes'     => $this->filterBy('type', 'like'),
      'replies'   => $this->filterBy('type', 'reply'),
      'mentions'  => $this->filterBy('type', 'mention'),
      'reposts'   => $this->filterBy('type', 'repost'),
      'bookmarks' => $this->filterBy('type', 'bookmark'),
      'headline'  => $this->headline
    ));

  }

  public function __toString() {
    return (string)$this->toHtml();
  }

}
