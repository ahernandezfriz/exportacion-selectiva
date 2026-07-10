( function () {
	'use strict';

	function qs( selector, root ) {
		return ( root || document ).querySelector( selector );
	}

	function qsa( selector, root ) {
		return Array.prototype.slice.call( ( root || document ).querySelectorAll( selector ) );
	}

	function setProgress( root, percent, statusText ) {
		var fill = qs( '.ahf-es-progress-bar-fill', root );
		var percentEl = qs( '.ahf-es-progress-percent', root );
		var statusEl = qs( '.ahf-es-progress-status', root );

		if ( fill ) {
			fill.style.width = percent + '%';
		}
		if ( percentEl ) {
			percentEl.textContent = percent + '%';
		}
		if ( statusEl && statusText ) {
			statusEl.textContent = statusText;
		}
	}

	function postForm( action, data ) {
		var body = new window.FormData();
		body.append( 'action', action );
		body.append( 'nonce', window.ahfEsAdmin.nonce );

		Object.keys( data ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( item ) {
					body.append( key + '[]', item );
				} );
			} else {
				body.append( key, value );
			}
		} );

		return window.fetch( window.ahfEsAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	function runExportLoop( jobId, card ) {
		var i18n = window.ahfEsAdmin.i18n || {};

		function tick() {
			return postForm( 'ahf_es_process_export', { job_id: jobId } ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || i18n.error );
				}

				var data = json.data;
				setProgress( card, data.percent || 0, i18n.processing );

				if ( data.status === 'done' ) {
					setProgress( card, 100, i18n.done );
					var actions = qs( '.ahf-es-progress-actions', card );
					var link = qs( '.ahf-es-download-link', card );
					if ( actions && link && data.download_url ) {
						link.href = data.download_url;
						actions.hidden = false;
					}
					return;
				}

				return tick();
			} );
		}

		return tick().catch( function ( error ) {
			setProgress( card, 0, error.message || i18n.error );
		} );
	}

	function runImportLoop( jobId, progressCard, resultCard, formCard ) {
		var i18n = window.ahfEsAdmin.i18n || {};

		function tick() {
			return postForm( 'ahf_es_process_import', { job_id: jobId } ).then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || i18n.error );
				}

				var data = json.data;
				setProgress( progressCard, data.percent || 0, i18n.processing );

				if ( data.status === 'done' ) {
					setProgress( progressCard, 100, i18n.done );
					progressCard.hidden = true;
					resultCard.hidden = false;

					var counts = data.result || data.counts || {};
					var text = qs( '.ahf-es-import-result-text', resultCard );
					if ( text ) {
						text.textContent =
							i18n.done +
							' ' +
							'Creados: ' + ( counts.created || 0 ) +
							'. Actualizados: ' + ( counts.updated || 0 ) +
							'. Duplicados: ' + ( counts.duplicated || 0 ) +
							'. Comparados: ' + ( counts.compared || 0 ) +
							'. Omitidos: ' + ( counts.skipped || 0 ) + '.';
					}
					return;
				}

				return tick();
			} );
		}

		return tick().catch( function ( error ) {
			setProgress( progressCard, 0, error.message || i18n.error );
			if ( formCard ) {
				formCard.hidden = false;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var checkAll = qs( '.ahf-es-check-all' );
		if ( checkAll ) {
			checkAll.addEventListener( 'change', function () {
				qsa( 'input[name="ahf_es_items[]"]' ).forEach( function ( item ) {
					item.checked = checkAll.checked;
				} );
			} );
		}

		if ( ! window.ahfEsAdmin ) {
			return;
		}

		if ( window.ahfEsAdmin.mode === 'export' ) {
			var exportCard = qs( '#ahf-es-progress-card' );
			if ( exportCard && window.ahfEsAdmin.jobId ) {
				runExportLoop( window.ahfEsAdmin.jobId, exportCard );
			}
			return;
		}

		if ( window.ahfEsAdmin.mode === 'import' ) {
			var form = qs( '#ahf-es-import-form' );
			if ( ! form ) {
				return;
			}

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();

				var items = qsa( 'input[name="ahf_es_items[]"]:checked' ).map( function ( el ) {
					return el.value;
				} );
				var policyEl = qs( 'input[name="ahf_es_conflict_policy"]:checked' );
				var sessionKey = qs( '#ahf_es_session_key' );
				var formCard = qs( '#ahf-es-import-form-card' );
				var progressCard = qs( '#ahf-es-import-progress' );
				var resultCard = qs( '#ahf-es-import-result' );

				if ( ! items.length || ! sessionKey ) {
					return;
				}

				if ( formCard ) {
					formCard.hidden = true;
				}
				if ( progressCard ) {
					progressCard.hidden = false;
					setProgress( progressCard, 0, window.ahfEsAdmin.i18n.processing );
				}

				postForm( 'ahf_es_start_import', {
					session_key: sessionKey.value,
					policy: policyEl ? policyEl.value : 'skip',
					post_type: window.ahfEsAdmin.postType || 'post',
					items: items
				} ).then( function ( json ) {
					if ( ! json || ! json.success ) {
						throw new Error( ( json && json.data && json.data.message ) || window.ahfEsAdmin.i18n.error );
					}
					return runImportLoop( json.data.job_id, progressCard, resultCard, formCard );
				} ).catch( function ( error ) {
					window.alert( error.message || window.ahfEsAdmin.i18n.error );
					if ( formCard ) {
						formCard.hidden = false;
					}
					if ( progressCard ) {
						progressCard.hidden = true;
					}
				} );
			} );
		}
	} );
}() );
