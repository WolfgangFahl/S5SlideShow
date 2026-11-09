<?php

/**
 * Extension to create slide shows from wiki pages using improved S5
 * (http://meyerweb.com/eric/tools/s5/)
 *
 * Copyright (c) 2010+ Vitaliy Filippov <vitalif@mail.ru>
 * Copyright (c) 2017-2020 Wolfgang Fahl
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

/**
 * @author Vitaliy Filippov <vitalif@mail.ru>
 * @author Wolfgang Fahl
 * @package MediaWiki
 * @subpackage Extensions
 */

class S5SlideShowHooks {
	/** @var string[] */
	public static $styles = [
		'core.css'    => 's5-core.css',
		'base.css'    => 's5-base.css',
		'framing.css' => 's5-framing.css',
		'pretty.css'  => '$skin/pretty.css',
	];
	/** @var bool */
	public static $parsingSlide = false;

	/**
	 * Setup the article view parser hooks for S5;
	 * ?action=slide replaces them in Render::createParser()
	 *
	 * @param \Parser $parser
	 */
	public static function ParserFirstCallInit( $parser ) {
		$parser->setHook(
			'slideshow', [ 'S5SlideShow\Render', 'slideshow_view' ]
		);
		$parser->setHook(
			'slide', 'S5SlideShow\Render::slideshow_legacy'
		);
		$parser->setHook(
			'slides', 'S5SlideShow\Render::slides_view'
		);
		$parser->setHook(
			'slidecss', 'S5SlideShow\Render::slidecss_view'
		);
	}

	// Setup hook for image scaling hack
	public static function Setup() {
		// global $wgActions;
		// echo "<pre>";var_dump($wgActions);exit;
		// global $egS5BrowserScaleHack, $wgHooks;
		// if ( $egS5BrowserScaleHack ) {
		// 	$wgHooks['ImageBeforeProduceHTML'][]
		// 		= 'MediaWiki\Extensions\S5SlideShow\Hooks::ImageBeforeProduceHTML';
		// }
	}

	// Hook that creates {{S5SLIDESHOW}} magic word
	public static function MagicWordwgVariableIDs( &$mVariablesIDs ) {
		$mVariablesIDs[] = 's5slideshow';
	}

	// Hook that evaluates {{S5SLIDESHOW}} magic word
	public static function ParserGetVariableValueSwitch( $parser, &$varCache, $index, &$ret ) {
		if ( $index === 's5slideshow' ) {
			$ret = $varCache[$index] = empty( self::$parsingSlide ) ? '' : '1';
		}
	}

	// Render pictures differently in slide show mode
	public static function ImageBeforeProduceHTML(
		$skin, $title, $file, $frameParams, $handlerParams, $time, &$res
	) {
		global $egS5BrowserScaleHack;
		if ( !$egS5BrowserScaleHack ) {
			return;
		}

		if (
			empty( self::$parsingSlide ) || !$file || !$file->exists() ||
			!isset( $handlerParams['width'] )
		) {
			return true;
		}
		$fp = &$frameParams;
		$hp = &$handlerParams;
		$center = false;
		if ( isset( $fp['align'] ) && $fp['align'] === 'center' ) {
			$center = true;
			$fp['align'] = 'none';
		}
		$thumb = $file->getUnscaledThumb(
			isset( $hp['page'] ) ? [ 'page' => $hp['page'] ] : false
		);
		$params['alt'] = $fp['alt'] ?? null;
		$params['title'] = $fp['title'] ?? null;

		$params['override-height']
			= ceil( $thumb->getHeight() * $hp['width'] / $thumb->getWidth() );
		$params['override-width'] = $hp['width'];
		if ( !empty( $fp['link-url'] ) ) {
			$params['custom-url-link'] = $fp['link-url'];
		} elseif ( !empty( $fp['link-title'] ) ) {
			$params['custom-title-link'] = $fp['link-title'];
		} elseif ( !empty( $fp['no-link'] ) ) {
		} else {
			$params['desc-link'] = true;
		}
		$res .= $thumb->toHtml( $params );
		if ( isset( $fp['thumbnail'] ) ) {
			$outerWidth = $thumb->getWidth() + 2;
			$res = "<div class='thumb t$fp[align]' style='border:0'>"
				 . "<div class='thumbinner'>$res</div>"
				 . "<div class='thumbcaption'>$fp[caption]</div></div>";
		}
		if ( isset( $fp['align'] ) && $fp['align'] ) {
			$res = "<div class='float$fp[align]'>$res</div>";
		}
		if ( $center ) {
			$res = "<div class='center'>$res</div>";
		}
		return false;
	}

	// Used to display CSS files on S5 skin CSS pages when they don't exist
	public static function ArticleFromTitle( $title, &$article ) {
		if ( $title->getNamespace() === NS_MEDIAWIKI &&
			 preg_match(
				 '#^S5/([\w-]+)/((core|base|framing|pretty).css)$#s', $title->getText(), $m
			 )
		) {
			$file = __DIR__ . '/' . str_replace( '$skin', $m[1], self::$styles[$m[2]] );
			if ( file_exists( $file ) ) {
				$article = new MWArticle( $title, $m[1], $file );
				return false;
			}
		}
		return true;
	}

	// Used to display CSS files on S5 skin CSS pages in edit mode
	public static function AlternateEdit( $editpage ) {
		$article = $editpage->getArticle();
		if ( $article instanceof MWArticle && !$article->getPage()->exists() ) {
			$editpage->mPreloadText = $article->getPage()->getContent();
		}
		return true;
	}
}
