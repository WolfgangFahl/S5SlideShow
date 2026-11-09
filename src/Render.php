<?php

/**
 * Extension to create slide shows from wiki pages using improved S5
 * (http://meyerweb.com/eric/tools/s5/)
 *
 * Copyright (c) 2010+ Vitaliy Filippov <vitalif@mail.ru>
 * Copyright (c) 2020  Wolfgang Fahl
 * Copyright (c) 2020  NicheWork, LLC
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 59 Temple Place - Suite 330, Boston, MA 02111-1307, USA.
 * http://www.gnu.org/copyleft/gpl.html
 */
namespace S5SlideShow;

use Article as MWArticle;
use MediaWiki\MediaWikiServices;
use Parser;
use ParserOptions;
use PPFrame;
use Title;
use User;

// more trouble than help
// use Wikimedia\AtEase\AtEase;

/**
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @author Mark A. Hershberger <mah@nichework.com>
 * @author Wolfgang Fahl <wf@bitplan.com>
 * @package MediaWiki
 * @subpackage Extensions
 */

class Render {
	/** @var Title */
	private $sTitle;
	/** @var MWArticle */
	private $sArticle;
	/** @var Content */
	private $pageContent;

	/** @var Parser */
	private $slideParser;
	/** @var ParserOptions */
	private $parserOptions;
	/** @var int */
	private static $slideno = 0;

	/** @var array */
	private $slides;
	/** @var array */
	private $css;
	/** @var array */
	public $attr;
	/** @var string|null pattern for the headingmark attribute */
	private $heading_re;
	/** @var string|null pattern for the incmark attribute */
	private $inc_re;
	/** @var string|null pattern for the centermark attribute */
	private $center_re;

	/**
	 * Constructor. If $attr is not an array(), article content
	 * will be parsed and attributes will be taken from there.
	 */
	public function __construct( $sTitle, $sContent = null, $attr = null ) {
		if ( !is_object( $sTitle ) ) {
			wfDebug( __CLASS__ . ": Error! Pass a title object, NOT a title string!\n" );
			return false;
		}
		$this->sArticle = new MWArticle( $sTitle );
		$this->sTitle = $sTitle;
		if ( $sContent ) {
			$this->pageContent = $sContent;
		} else {
			$this->pageContent = $this->sArticle->getPage()->getContent();
		}
		$this->attr = [];
		if ( is_array( $attr ) ) {
			$this->setAttributes( $attr );
		}
	}

	/**
	 * Parse attribute hash and save them into $this
	 */
	public function setAttributes( $attr ) {
		global $egS5SlideHeadingMark, $egS5SlideIncMark,
			$egS5SlideCenterMark, $egS5DefaultStyle, $egS5Scaled;
		// Get attributes from tag content
		if (
			preg_match_all(
				'/(?:^|\n)\s*;\s*([^:\s]*)\s*:\s*([^\n]*)/is', $attr['content'], $m,
				PREG_SET_ORDER
			) > 0
		) {
			foreach ( $m as $set ) {
				$attr[$set[1]] = trim( $set[2] );
			}
		}
		// Default values
		$attr = $attr + [
			'title'       => $this->sTitle->getText(),
			'subtitle'    => '',
			'footer'      => $this->sTitle->getText(),
			'headingmark' => $egS5SlideHeadingMark,
			'incmark'     => $egS5SlideIncMark,
			'centermark'  => $egS5SlideCenterMark,
			'style'       => $egS5DefaultStyle,
			'font'        => '',
			// Backwards compatibility: appended CSS
			'addcss'      => '',
			// Is each slide to be scaled independently?
			'scaled'      => $egS5Scaled,
		];
		// Boolean value
		$attr['scaled'] = strtoupper( $attr['scaled'] ) === 'TRUE'
						|| strtoupper( $attr['scaled'] ) === 'YES'
						|| intval( $attr['scaled'] ) === 1;
		// Default author = first revision's author
		if ( !isset( $attr['author'] ) ) {
			$services = MediaWikiServices::getInstance();
			$rev = $services->getRevisionLookup()->getFirstRevision( $this->sTitle );
			$revUser = $rev ? $rev->getUser() : null;
			if ( $revUser ) {
				$attr['author'] = $services->getUserFactory()
					->newFromUserIdentity( $revUser )->getRealName();
			} else {
				// Not saved yet
				$attr['author'] = $this->sArticle->getContext()->getUser()->getRealName();
			}
		}
		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
		// Author and date in the subfooter by default
		if ( !isset( $attr['subfooter'] ) ) {
			$attr['subfooter'] = $attr['author'];
			if ( $attr['subfooter'] ) {
				$attr['subfooter'] .= ', ';
			}
			$attr['subfooter'] .= $contentLanguage->timeanddate(
				$this->sArticle->getPage()->getTimestamp(), true
			);
		} else {
			$attr['subfooter'] = str_ireplace(
				'{{date}}',
				$contentLanguage->timeanddate( $this->sArticle->getPage()->getTimestamp(), true ),
				$attr['subfooter']
			);
		}
		$this->attr = $attr + $this->attr;
	}

	/**
	 * Checks heading text for headingmark, incmark, centermark
	 *
	 * @param string $heading the wikitext of a heading including its = signs
	 * @return array|null [ title, incremental, centered ],
	 * null if the heading does not start a slide
	 */
	public function check_slide_heading( $heading ) {
		$ot = trim( $heading, "= \n\r\t\v" );
		$st = $this->attr['headingmark'] ? preg_replace( $this->heading_re, '', $ot ) : $ot;
		if ( !$this->attr['headingmark'] || $st != $ot ) {
			$inc = false;
			$center = false;
			if ( $this->inc_re ) {
				$t = preg_replace( $this->inc_re, '', $st );
				if ( $t != $st ) {
					$inc = true;
					$st = $t;
				}
			}
			if ( $this->center_re ) {
				$t = preg_replace( $this->center_re, '', $st );
				if ( $t != $st ) {
					$center = true;
					$st = $t;
				}
			}
			return [ $st, $inc, $center ];
		}
		return null;
	}

	/**
	 * The regular expression for one of the slideshow attributes
	 * headingmark, incmark and centermark
	 *
	 * @param string $name the name of the attribute
	 * @return string|null the pattern, null if the attribute is not set
	 */
	private function mark_pattern( $name ) {
		$pattern = null;
		if ( !empty( $this->attr[$name] ) ) {
			$pattern = '/' . preg_quote( $this->attr[$name], '/' ) . '/';
		}
		return $pattern;
	}

	/**
	 * The opening <slides> tag for a slide heading
	 *
	 * @param array $st [ title, incremental, centered ] as of check_slide_heading
	 * @return string
	 */
	private function slides_open_tag( array $st ) {
		$tag = '<slides title="' . htmlspecialchars( $st[0] ) . '"';
		if ( $st[1] ) {
			$tag .= ' incremental="1"';
		}
		if ( $st[2] ) {
			$tag .= ' center="1"';
		}
		$tag .= '>';
		return $tag;
	}

	/**
	 * Is the given preprocessor node a <slide>, <slides>, <slideshow>
	 * or <slidecss> extension tag?
	 *
	 * @param \PPNode $node
	 * @param PPFrame $frame
	 * @return bool
	 */
	private function is_slide_tag( $node, $frame ) {
		$isSlideTag = false;
		if ( $node->getName() === 'ext' ) {
			$ext = $node->splitExt();
			$name = $frame->expand( $ext['name'], PPFrame::RECOVER_ORIG );
			$isSlideTag = substr( $name, 0, 5 ) === 'slide';
		}
		return $isSlideTag;
	}

	/**
	 * This function transforms section slides into <slides> tags
	 *
	 * It walks the top level nodes of the preprocessor tree, so a heading
	 * within nowiki or within a template argument never starts a slide.
	 * A slide runs from its heading to the next heading or slide tag.
	 */
	private function transform_section_slides( $content ) {
		$p = $this->getParser();
		$content = $p->preprocess( $content, $this->sTitle, $this->parserOptions );
		$p->setOutputType( Parser::OT_WIKI );
		$root = $p->preprocessToDom( $content );
		$frame = $p->getPreprocessor()->newFrame();
		$this->heading_re = $this->mark_pattern( 'headingmark' );
		$this->inc_re = $this->mark_pattern( 'incmark' );
		$this->center_re = $this->mark_pattern( 'centermark' );
		$text = '';
		// the closing tag of the slide that is open at this point, if any
		$close = '';
		for ( $node = $root->getFirstChild(); $node; $node = $node->getNextSibling() ) {
			$wikitext = $frame->expand( $node, PPFrame::RECOVER_ORIG );
			$isHeading = $node->getName() === 'h';
			$st = $isHeading ? $this->check_slide_heading( $wikitext ) : null;
			if ( $st !== null ) {
				// a slide heading ends the open slide and starts the next one
				$text .= $close . $this->slides_open_tag( $st );
				$close = "</slides>\n";
			} elseif ( $isHeading || $this->is_slide_tag( $node, $frame ) ) {
				// any other heading and any slide tag ends the open slide
				$text .= $close . $wikitext;
				$close = '';
			} else {
				$text .= $wikitext;
			}
		}
		$text .= $close;
		$text = $p->getStripState()->unstripBoth( $text );
		return $text;
	}

	/**
	 * Extract slides from wiki-text $content
	 */
	private function loadContent( $content = null ) {
		if ( $content === null ) {
			if ( method_exists( $this->pageContent, 'getText' ) ) {
				$content = $this->pageContent->getText();
			} else {
				// Pre 1.33 compatibility
				$content = $this->pageContent->getNativeData();
			}
		}
		$this->getParser();
		$p = MediaWikiServices::getInstance()->getParserFactory()->create();
		$p->setHook( 'slideshow', [ $this, 'slideshow_parse' ] );
		$p->setHook( 'slide', [ $this, 'slideshow_parse' ] );
		$p->setHook( 'slides', __CLASS__ . '::empty_tag_hook' );
		$p->setHook( 'slidecss', __CLASS__ . '::empty_tag_hook' );
		$p->parse( $content, $this->sTitle, $this->parserOptions );
		if ( isset( $this->attr['headingmark'] ) && $this->attr['headingmark'] !== false ) {
			$content = $this->transform_section_slides( $content );
		}
		$this->slides = [];
		$this->css = [];
		$this->parse( $content );
		foreach ( $this->slides as &$slide ) {
			$slide['content_html'] = $this->parse( $slide['content'] );
			if ( $slide['title'] ) {
				$slide['title_html'] = $this->parse( $slide['title'], true );
			} else {
				$slide['title_html'] = '';
			}
		}
		return $this->slides;
	}

	/**
	 * Parse slide content using a copy of Parser,
	 * save slides and slide stylesheets into $this and return resulting HTML
	 */
	private function parse( $text, $inline = false, $title = null ) {
		global $wgOut;
		if ( !$title ) {
			$title = $this->sTitle;
		}
		$text = str_replace( "__TOC__", '', trim( $text ) );
		$prev = S5SlideShowHooks::$parsingSlide;
		S5SlideShowHooks::$parsingSlide = true;
		$output = $this->getParser()->parse(
			"$text __NOTOC__ __NOEDITSECTION__", $title,
			$this->parserOptions, !$inline, false
		);
		S5SlideShowHooks::$parsingSlide = $prev;
		$wgOut->addParserOutput( $output );
		return $output->getText( [ 'enableSectionEditLinks' => false ] );
	}

	// Create parser object for $this->parse()
	private function getParser() {
		if ( $this->slideParser ) {
			return $this->slideParser;
		}
		$this->parserOptions = ParserOptions::newFromContext( $this->sArticle->getContext() );
		$this->parserOptions->enableLimitReport( false );
		// Since $this->parse() is only used in ?action=slide,
		// we can use it directly without cloning or creating a new object
		// But Parser may be a StubObject, so trigger unstub and first call init
		$parser = MediaWikiServices::getInstance()->getParser();
		$parser->parse( " ", $this->sTitle, $this->parserOptions, false, true );
		$parser->setHook( 'slideshow', __CLASS__ . '::empty_tag_hook' );
		$parser->setHook( 'slide', __CLASS__ . '::empty_tag_hook' );
		$parser->setHook( 'slides', [ $this, 'slides_parse' ] );
		$parser->setHook( 'slidecss', [ $this, 'slidecss_parse' ] );
		$this->slideParser = $parser;
		return $this->slideParser;
	}

	private function getHeadItems() {
		// Extract loader scripts and styles from OutputPage::headElement()
		global $wgOut;
		// a skin needs a name; the options are those of the apioutput skin of core
		$skin = new Skin( [
			'name' => 'apioutput',
			'styles' => [ 'mediawiki.skinning.interface' ],
		] );
		$context = $wgOut->getContext();
		$context->setSkin( $skin );
		$s = $wgOut->headElement( $skin );
		preg_match_all(
			'/<script[^<>]*>.*?<\/script>|<link[^<>]*rel="stylesheet"[^<>]*>|' .
			'<meta[^<>]*name="ResourceLoaderDynamicStyles"[^<>]*>/is',
			$s, $m, PREG_PATTERN_ORDER
		);
		return implode(
			"\n", array_filter( $m[0],
								static function ( $s ) {
									return strpos( $s, 'commonPrint' ) === false;
								} )
		);
	}

	/**
	 * Generate presentation HTML code
	 */
	public function genSlideFile( $printPageSize = false ) {
		global $egS5SlideTemplateFile;
		global $wgOut;

		if ( !$this->slides ) {
			$this->loadContent();
		}

		$currentDir = getcwd();
		$debugMsg = ": trying to load slide template from " . $egS5SlideTemplateFile . " relative to " . $currentDir;
		wfDebug( __CLASS__ . $debugMsg );

		// load template contents
		// AtEase::quietCall( 'file_get_contents'
		$slide_template = file_get_contents( $egS5SlideTemplateFile );
		if ( !$slide_template ) {
			return false;
		}

		// build replacement array for slide show template
		$replace = [];
		foreach ( explode( ' ', 'title subtitle author footer subfooter addcss' ) as $v ) {
			$replace["[$v]"] = $this->parse( $this->attr[$v], true );
		}
		$replace['[addcss]'] = implode( "\n", $this->css );
		if ( $this->attr['font'] ) {
			$replace['[addcss]'] .= "\n.slide, div.header, div.footer { "
								 . "font-family: {$this->attr['font']}; }";
		}
		$replace['[addcss]'] = strip_tags( $replace['[addcss]'] );
		$replace['[addscript]'] = '';
		$replace['[style]'] = $this->attr['style'];
		$replace['[styleurl]'] = 'index.php?action=slide&s5skin='
							   . $this->attr['style'] . '&s5css=1';
		$replace['[pageid]'] = $this->sArticle->getPage()->getId();
		$replace['[scaled]'] = $this->attr['scaled'] ? 'true' : 'false';
		$replace['[defaultView]'] = 'slideshow';

		if ( $printPageSize ) {
			// Default DPI
			$dpi = 96;
			$printPageSize = array_map( 'intval', $printPageSize );
			$replace['[styleurl]'] .= '&print=' . implode( 'x', $printPageSize );
			$replace['[addcss]'] .= '@page {size: ' . $printPageSize[0] . 'mm '
								 . $printPageSize[1] . "mm;}\n.body {width: "
								 . ( $w = floor( $printPageSize[0] * $dpi / 25.4 ) )
								 . 'px; height: '
								 . ( $h = floor( $printPageSize[1] * $dpi / 25.4 ) )
								 . "px;}\n";
			$replace['[addscript]'] .= "var s5PrintPageSize = [ $w, $h ];\n";
			$replace['[defaultView]'] = 'print';
		}

		$slides_html = '';
		$slide0 = " visible";
		if ( trim( $replace['[author]'] ) !== '' && trim( $replace['[title]'] ) !== '' ) {
			$slides_html .= '<div class="slide' . $slide0 . '"><h1 class="stitle" '
						 . 'style="margin-top: 0">' . $replace['[title]']
						 . '</h1><div class="slidecontent">'
						 . '<h1 style="margin-top: 0; font-size: 60%">'
						 . $replace['[subtitle]'] . '</h1><h3>' . $replace['[author]']
						 . '</h3></div></div>';
			$slide0 = '';
		}
		foreach ( $this->slides as $slide ) {
			$c = $slide['content_html'];
			$t = $slide['title_html'];
			// make slide lists incremental if needed
			if ( $slide['incremental'] ) {
				$c = str_replace( '<ul>', '<ul class="anim">', $c );
				$c = str_replace( '<ol>', '<ol class="anim">', $c );
			}
			$c = "<div class='slidecontent'>$c</div>";
			if ( trim( strip_tags( $t ) ) ) {
				$center = $slide['center'] ? " notitle" : "";
				$slides_html .= "<div class='slide$slide0$center'>"
							 . "<h1 class='stitle'>$t</h1>$c</div>\n";
			} else {
				$slides_html .= "<div class='slide$slide0 notitle'>$c</div>\n";
			}
			$slide0 = "";
		}

		// substitute values
		$replace['[headitems]'] = $this->getHeadItems();
		$replace['[content]'] = $slides_html;
		$html = str_replace(
			array_keys( $replace ),
			array_values( $replace ),
			$slide_template
		);
		$services = MediaWikiServices::getInstance();
		$html = $services->getLanguageConverterFactory()
			->getLanguageConverter( $services->getContentLanguage() )
			->convert( $html );

		// output generated content
		$wgOut->disable();
		header( "Content-Type: text/html; charset=utf-8" );
		echo $html;
	}

	/**
	 * Function to replace URLs in S5 skin stylesheet
	 * $m is the match array coming from preg_replace_callback
	 */
	private static function styleReplaceUrl( $skin, $m ) {
		$t = Title::newFromText( $m[1], NS_FILE );
		if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
			// MediaWiki 1.34+
			$f = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo()
				->newFile( $t );
		} else {
			$f = wfLocalFile( $t );
		}
		if ( $f->exists() ) {
			return 'url(' . $f->getFullUrl() . ')';
		}
		// FIXME remove hardcode extensions/S5SlideShow/
		// Replace images with invalid names with blank.gif
		if ( preg_match( '/[^a-z0-9_\-\.]/is', $m[1] ) ) {
			return 'url(extensions/S5SlideShow/blank.gif)';
		}
		return "url(extensions/S5SlideShow/$skin/" . $m[1] . ')';
	}

	// https://stackoverflow.com/a/834355/1497139
	private static function endsWith( string $haystack, string $needle ): bool {
		return substr( $haystack, -strlen( $needle ) ) === $needle;
	}

	/**
	 * Generate CSS stylesheet for a given S5 skin
	 * TODO cache generated stylesheets and flush the cache after saving style articles
	 */
	public static function genStyle( $skin, $print = false ) {
		global $wgOut;
		// directory of script
		$dir = __DIR__;
		// since 2020-09 the sources are in src subdirectory
		if ( self::endsWith( $dir, "src" ) ) {
			$dir = dirname( $dir );
		}
		$css = '';
		if ( $print ) {
			S5SlideShowHooks::$styles['print'] = 'print.css';
		}
		foreach ( S5SlideShowHooks::$styles as $k => $file ) {
			// first try whether there is a page for the style
			$title = Title::newFromText( "S5/$skin/$k", NS_MEDIAWIKI );
			if ( $title->exists() ) {
				$a = new MWArticle( $title );
				$c = $a->getContent();
			} else {
				// try getting page from file system
				// AtEase::quietCall('file_get_contents'
				$skinFile = "$dir/" . str_replace( '$skin', $skin, $file );
				$c = file_get_contents( $skinFile );
			}
			$c = preg_replace_callback(
				'#url\(([^\)]*)\)#is', function ( $m ) use ( $skin ) {
					return self::styleReplaceUrl( $skin, $m );
				}, $c );
			$css .= $c;
		}
		$wgOut->disable();
		header( "Content-Type: text/css" );
		echo $css;
	}

	/**
	 * <slideshow> - article view mode, backwards compatibility
	 */
	public static function slideshow_legacy( $content, $attr, $parser, $frame = null ) {
		return self::slideshow_view(
			$content, $attr, $parser, $frame,
			'<div style="width: 240px; color: red">Warning: legacy &lt;slide&gt;' .
			' parser hook used, change it to &lt;slideshow&gt; please</div>'
		);
	}

	/**
	 * Parse content using an existing parser and cloned options
	 * without LimitReport, without EditSections
	 */
	private static function clone_options_parse( $content, $parser, $inline = false ) {
		if ( !$parser->getTitle() ) {
			wfDebug( __METHOD__ . ": no title object in parser\n" );
			return '';
		}
		$oldOpt = $parser->getOptions();
		if ( !isset( $parser->extClonedOptions ) ) {
			if ( !$oldOpt ) {
				$oldOpt = ParserOptions::newFromContext( \RequestContext::getMain() );
			}
			$opt = clone $oldOpt;
			$opt->enableLimitReport( false );
			$parser->extClonedOptions = $opt;
		}
		$html = $parser->parse(
			$content, $parser->getTitle(), $parser->extClonedOptions, !$inline, false
		)->getText( [ 'enableSectionEditLinks' => false ] );
		$parser->setOptions( $oldOpt );
		return $html;
	}

	/**
	 * <slideshow> - article view mode
	 * displays a link to the slideshow and skin preview
	 */
	public static function slideshow_view(
		$content, $attr, $parser, $frame = null, $addmsg = ''
	) {
		global $wgScriptPath;
		if ( !$parser->getTitle() ) {
			wfDebug( __METHOD__ . ": no title object in parser\n" );
			return '';
		}
		// Create slideshow object
		$attr['content'] = $content;
		$slideShow = new Render( $parser->getTitle(), null, $attr );
		$content = '';
		$article = new MWArticle( $parser->getTitle() );
		$contentLanguage = MediaWikiServices::getInstance()->getContentLanguage();
		foreach ( [ 'title', 'subtitle', 'author', 'footer', 'subfooter' ] as $key ) {
			if ( isset( $slideShow->attr[$key] ) && $slideShow->attr[$key] != '' ) {
				$value = $slideShow->attr[$key];
				if ( mb_strpos( $value, "{{date}}" ) !== false ) {
					$value = str_ireplace(
						'{{date}}',
						$contentLanguage->timeanddate( $article->getPage()->getTimestamp(), true ),
						$value
					);
				}
				$content .= "\n;" . wfMessage( 's5slide-header-' . $key ) . ': ' . $value;
			}
		}
		// FIXME remove hardcoded '.png', /extensions/S5SlideShow/, "Slide Show"
		$url = htmlspecialchars( $parser->getTitle()->getLocalUrl( [ 'action' => 'slide' ] ) );
		$style_title = Title::newFromText(
			'S5-' . $slideShow->attr['style'] . '-preview.png', NS_FILE
		);

		$parser = MediaWikiServices::getInstance()->getParser();
		if ( method_exists( MediaWikiServices::class, 'getRepoGroup' ) ) {
			// MediaWiki 1.34+
			$localRepo = MediaWikiServices::getInstance()->getRepoGroup()->getLocalRepo();
		} else {
			$localRepo = RepoGroup::singleton()->getLocalRepo();
		}
		if (
			$style_title && ( $style_preview = $localRepo->newFile( $style_title ) ) &&
			$style_preview->exists()
		) {
			$style_preview = $style_preview->getTitle()->getPrefixedText();
			$style_preview = self::clone_options_parse(
				"[[$style_preview|240px|link=]]", $parser, true
			);
		} else {
			$style_preview = '<img src="' . $wgScriptPath . '/extensions/S5SlideShow/'
						   . $slideShow->attr['style'] . '/preview.png" '
						   . 'alt="Slide Show" width="240px" />';
		}
		$inside = self::clone_options_parse( $content, $parser, true );
		$html = '<script type="text/javascript" src="' . $wgScriptPath
			  . '/extensions/S5SlideShow/contentScale.js"></script>'
			  . '<script type="text/javascript" src="' . $wgScriptPath
			  . '/extensions/S5SlideShow/slideView.js"></script>'
			  . '<div class="floatright" style="text-align: center"><span>'
			  . '<a href="' . $url . '" class="image" title="Slide Show" target="_blank">'
			  . $style_preview . '<br />' . 'Slide Show</a></span>' . $addmsg . '</div>'
			  . $inside;
		if ( !empty( $slideShow->attr['font'] ) ) {
			$html = '<script type="text/javascript">var wgSlideViewFont = "'
				  . addslashes( $slideShow->attr['font'] ) . '";</script>' . $html;
		}
		$html = '<div id="slideshow-bundle">' . $html . '</div>';
		return $html;
	}

	// <slideshow> - slideshow parse mode
	// saves parameters into $this
	public function slideshow_parse( $content, $attr, $parser ) {
		$attr['content'] = $content;
		$this->setAttributes( $attr );
		return '';
	}

	// <slides> - article view mode
	public static function slides_view( $content, $attr, $parser ) {
		if ( !empty( $attr['split'] ) ) {
			$slides = preg_split(
				'/' . str_replace( '/', '\\/', $attr['split'] ) . '/', $content
			);
		} else {
			$slides = [ $content ];
		}
		$html = '';
		$style = '';
		if ( !isset( $attr['float'] ) ) {
			$style .= "float: left; ";
		}
		if ( isset( $attr['width'] ) ) {
			$style .= "width: $attr[width]px; ";
		}
		if ( $style ) {
			$style = " style='$style'";
		}
		foreach ( $slides as $i => $slide ) {
			if ( isset( $attr['title'] ) && !$i ) {
				$slide = "== $attr[title] ==\n" . trim( $slide );
				$st = 'slide withtitle';
			} else {
				$st = 'slide';
			}
			$output = self::clone_options_parse( trim( $slide ), $parser, false );
			$html .= '<div class="' . $st . '" ' . $style . ' id="slide'
				  . ( self::$slideno++ ) . '">' . $output . '</div>';
		}
		if ( !isset( $attr['float'] ) ) {
			$html .= '<div style="clear: both"></div>';

		} else {
			$html = "<div style='float: $attr[float]; margin: "
				  . (
					  strtoupper( $attr['float'] ) === 'LEFT'
					  ? '0 1em 1em 0'
					  : '0 0 0 1em'
				  ) . "'>$html</div>";
		}
		return $html;
	}

	// <slides> - slideshow parse mode
	public function slides_parse( $content, $attr, $parser ) {
		if ( isset( $attr['split'] ) ) {
			$slides = preg_split(
				'/' . str_replace( '/', '\\/', $attr['split'] ) . '/', $content
			);
		} else {
			$slides = [ $content ];
		}
		foreach ( $slides as $c ) {
			$this->slides[] = [ 'content' => trim( $c ) ] + $attr + [
				'title' => '',
				'incremental' => false,
				'center' => false,
			];
			unset( $attr['title'] );
		}
	}

	// <slidecss> - article view mode
	public static function slidecss_view( $content, $attr, $parser ) {
		// use this CSS only for <slidecss view="true">
		if (
			!empty( $attr['view'] ) && ( $attr['view'] == 'true' || $attr['view'] == '1' )
		) {
			$parser->getOutput()->addHeadItem(
				'<style type="text/css">' . $content . '</style>'
			);
		}
		return '';
	}

	// <slidecss> - slideshow parse mode
	public function slidecss_parse( $content, $attr, $parser ) {
		$this->css[] = $content;
	}

	// stub for tag hooks
	public static function empty_tag_hook() {
		return '';
	}
}
