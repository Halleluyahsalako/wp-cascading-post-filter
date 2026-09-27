/**
 * Cascading Post Filter
 *
 * No jQuery. Cards are built with createElement so post titles and excerpts
 * cannot inject markup into the page.
 */
( function () {
	'use strict';

	var vars = window.cpfilterVars || {};

	function init( root ) {
		var state = {
			search: '',
			year: '',
			parent: '',
			child: '',
			paged: 1,
			postType: root.dataset.postType || 'post',
			taxonomy: root.dataset.taxonomy || 'category',
			perPage: parseInt( root.dataset.perPage, 10 ) || 12
		};

		var grid = root.querySelector( '.cpfilter__grid' );
		var meta = root.querySelector( '.cpfilter__meta' );
		var pagination = root.querySelector( '.cpfilter__pagination' );
		var search = root.querySelector( '.cpfilter__search-input' );
		var parentSelect = root.querySelector( '[data-filter="parent"]' );
		var childSelect = root.querySelector( '[data-filter="child"]' );
		var childField = root.querySelector( '.cpfilter__field--child' );
		var yearSelect = root.querySelector( '[data-filter="year"]' );
		var clear = root.querySelector( '.cpfilter__clear' );
		var controller = null;
		var timer = null;

		root.style.setProperty( '--cpfilter-columns', parseInt( root.dataset.columns, 10 ) || 3 );

		if ( search ) {
			search.addEventListener( 'input', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( function () {
					state.search = search.value.trim();
					state.paged = 1;
					load();
				}, 400 );
			} );
		}

		if ( yearSelect ) {
			yearSelect.addEventListener( 'change', function () {
				state.year = yearSelect.value;
				state.paged = 1;
				load();
			} );
		}

		if ( parentSelect ) {
			parentSelect.addEventListener( 'change', function () {
				state.parent = parentSelect.value;
				state.child = '';
				state.paged = 1;

				loadChildren( state.parent );
				load();
			} );
		}

		if ( childSelect ) {
			childSelect.addEventListener( 'change', function () {
				state.child = childSelect.value;
				state.paged = 1;
				load();
			} );
		}

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				state.search = '';
				state.year = '';
				state.parent = '';
				state.child = '';
				state.paged = 1;

				if ( search ) {
					search.value = '';
				}
				if ( yearSelect ) {
					yearSelect.value = '';
				}
				if ( parentSelect ) {
					parentSelect.value = '';
				}
				hideChildren();
				load();
			} );
		}

		pagination.addEventListener( 'click', function ( e ) {
			var button = e.target.closest( '[data-page]' );

			if ( ! button ) {
				return;
			}

			state.paged = parseInt( button.dataset.page, 10 );
			load();
			root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
		} );

		/* ----------------------------------------------------------------
		 * Cascading child terms
		 * -------------------------------------------------------------- */

		function hideChildren() {
			if ( ! childField ) {
				return;
			}
			childField.hidden = true;
			childSelect.length = 1;
			childSelect.value = '';
		}

		function loadChildren( parentId ) {
			if ( ! childField || ! parentId ) {
				hideChildren();
				return;
			}

			post( 'cpfilter_children', {
				taxonomy: state.taxonomy,
				parent: parentId
			} )
				.then( function ( data ) {
					childSelect.length = 1;

					if ( ! data.terms.length ) {
						hideChildren();
						return;
					}

					data.terms.forEach( function ( term ) {
						var option = document.createElement( 'option' );
						option.value = term.id;
						option.textContent = term.name + ' (' + term.count + ')';
						childSelect.appendChild( option );
					} );

					childField.hidden = false;
				} )
				.catch( hideChildren );
		}

		/* ----------------------------------------------------------------
		 * Results
		 * -------------------------------------------------------------- */

		function load() {
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();

			grid.setAttribute( 'aria-busy', 'true' );
			grid.innerHTML = '<div class="cpfilter__spinner" aria-hidden="true"></div>';

			post( 'cpfilter_query', {
				post_type: state.postType,
				taxonomy: state.taxonomy,
				search: state.search,
				year: state.year,
				parent: state.parent,
				child: state.child,
				paged: state.paged,
				per_page: state.perPage
			}, controller.signal )
				.then( render )
				.catch( function ( error ) {
					if ( 'AbortError' === error.name ) {
						return;
					}
					grid.innerHTML = '';
					meta.textContent = vars.i18n.error;
				} )
				.finally( function () {
					grid.setAttribute( 'aria-busy', 'false' );
				} );
		}

		function render( data ) {
			pagination.innerHTML = '';

			if ( ! data.posts.length ) {
				grid.innerHTML = '';
				meta.textContent = vars.i18n.noResults;
				return;
			}

			var first = ( data.page - 1 ) * data.perPage + 1;
			var last = Math.min( data.page * data.perPage, data.found );

			meta.textContent = vars.i18n.showing
				.replace( '%1$s', first )
				.replace( '%2$s', last )
				.replace( '%3$s', data.found );

			grid.innerHTML = '';
			data.posts.forEach( function ( item ) {
				grid.appendChild( card( item ) );
			} );

			if ( data.maxPages > 1 ) {
				renderPagination( data );
			}
		}

		function card( item ) {
			var article = el( 'article', 'cpfilter__card' );

			if ( item.thumbnail ) {
				var media = el( 'a', 'cpfilter__card-media' );
				media.href = item.permalink;

				var img = document.createElement( 'img' );
				img.src = item.thumbnail;
				img.alt = '';
				img.loading = 'lazy';
				media.appendChild( img );

				if ( item.terms.length ) {
					media.appendChild( el( 'span', 'cpfilter__badge', item.terms[ 0 ] ) );
				}

				article.appendChild( media );
			}

			var body = el( 'div', 'cpfilter__card-body' );

			body.appendChild( el( 'time', 'cpfilter__date', item.date ) );

			var heading = el( 'h3', 'cpfilter__card-title' );
			var link = document.createElement( 'a' );
			link.href = item.permalink;
			link.textContent = item.title;
			heading.appendChild( link );
			body.appendChild( heading );

			if ( item.excerpt ) {
				body.appendChild( el( 'p', 'cpfilter__card-excerpt', item.excerpt ) );
			}

			var more = document.createElement( 'a' );
			more.className = 'cpfilter__more';
			more.href = item.permalink;
			more.textContent = vars.i18n.readMore;
			body.appendChild( more );

			article.appendChild( body );
			return article;
		}

		function renderPagination( data ) {
			pageNumbers( data.page, data.maxPages ).forEach( function ( page ) {
				if ( '...' === page ) {
					pagination.appendChild( el( 'span', 'cpfilter__gap', '…' ) );
					return;
				}

				var button = document.createElement( 'button' );
				button.type = 'button';
				button.className = 'cpfilter__page' + ( page === data.page ? ' is-current' : '' );
				button.dataset.page = page;
				button.textContent = page;

				if ( page === data.page ) {
					button.setAttribute( 'aria-current', 'page' );
				}

				pagination.appendChild( button );
			} );
		}

		function pageNumbers( current, total ) {
			if ( total <= 7 ) {
				return range( 1, total );
			}
			if ( current <= 4 ) {
				return range( 1, 5 ).concat( [ '...', total ] );
			}
			if ( current >= total - 3 ) {
				return [ 1, '...' ].concat( range( total - 4, total ) );
			}
			return [ 1, '...' ].concat( range( current - 1, current + 1 ), [ '...', total ] );
		}

		function range( from, to ) {
			var out = [];
			for ( var i = from; i <= to; i++ ) {
				out.push( i );
			}
			return out;
		}

		function el( tag, className, text ) {
			var node = document.createElement( tag );
			node.className = className;
			if ( undefined !== text ) {
				node.textContent = text;
			}
			return node;
		}

		function post( action, payload, signal ) {
			var body = new window.FormData();
			body.append( 'action', action );
			body.append( 'nonce', vars.nonce );

			Object.keys( payload ).forEach( function ( key ) {
				body.append( key, payload[ key ] );
			} );

			return window.fetch( vars.ajaxUrl, {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
				signal: signal
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( 'Request failed' );
					}
					return json.data;
				} );
		}

		load();
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.cpfilter' ).forEach( init );
	} );
} )();
