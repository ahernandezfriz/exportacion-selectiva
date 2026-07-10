( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var checkAll = document.querySelector( '.ahf-es-check-all' );

		if ( ! checkAll ) {
			return;
		}

		checkAll.addEventListener( 'change', function () {
			var items = document.querySelectorAll( 'input[name="ahf_es_items[]"]' );

			items.forEach( function ( item ) {
				item.checked = checkAll.checked;
			} );
		} );
	} );
}() );
