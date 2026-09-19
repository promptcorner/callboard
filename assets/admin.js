/* global jQuery */

( ( $ ) => {
	'use strict';

	const list = $( '#callboard-track-list' );
	if ( ! list.length ) {
		return;
	}

	const config = window.CALLBOARD_ADMIN || {};
	const empty = $( '.callboard-track-empty' );
	const removals = $( '#callboard-track-removals' );
	const warning = $( '.callboard-track-warning' );
	let frame;

	const updateState = () => {
		empty.prop( 'hidden', list.children().length > 0 );
		list.toggleClass( 'is-empty', list.children().length === 0 );
		list.find( '.callboard-move[data-dir="-1"]' )
			.prop( 'disabled', false )
			.first()
			.prop( 'disabled', true );
		list.find( '.callboard-move[data-dir="1"]' )
			.prop( 'disabled', false )
			.last()
			.prop( 'disabled', true );
	};

	const move = ( button ) => {
		const row = button.closest( 'li' );
		if ( Number( button.data( 'dir' ) ) < 0 ) {
			row.prev().before( row );
		} else {
			row.next().after( row );
		}
		updateState();
		button.trigger( 'focus' );
	};

	const detailRow = ( id ) => {
		const details = $( '<details>', { class: 'callboard-track-more' } );
		details.append( $( '<summary>' ).text( config.details ) );

		const tempo = $( '<p>' );
		tempo.append(
			$( '<label>', { for: `callboard-bpm-${ id }` } ).text(
				config.tempo
			),
			document.createTextNode( ' ' ),
			$( '<input>', {
				type: 'number',
				id: `callboard-bpm-${ id }`,
				name: `callboard_bpm[${ id }]`,
				min: 30,
				max: 300,
				step: 1,
				class: 'small-text',
			} ),
			document.createTextNode( ' ' ),
			$( '<span>', { class: 'description' } ).text( config.tempoHelp )
		);

		const notes = $( '<p>' );
		notes.append(
			$( '<label>', { for: `callboard-notes-${ id }` } ).text(
				config.notes
			),
			$( '<textarea>', {
				id: `callboard-notes-${ id }`,
				name: `callboard_notes[${ id }]`,
				rows: 3,
				class: 'large-text code',
				placeholder: config.notesPlaceholder,
			} ),
			$( '<span>', { class: 'description' } ).text( config.notesHelp )
		);

		return details.append(
			$( '<div>', { class: 'callboard-track-fields' } ).append(
				tempo,
				notes
			)
		);
	};

	const addTrack = ( attachment ) => {
		const id = Number( attachment.id );
		if ( ! id || list.children( `[data-id="${ id }"]` ).length ) {
			return;
		}

		removals.find( `input[value="${ id }"]` ).remove();
		const title = attachment.get( 'title' ) || attachment.get( 'filename' );
		const row = $( '<li>', { 'data-id': id } );
		const controls = $( '<div>', { class: 'callboard-track-row' } );
		controls.append(
			$( '<span>', {
				class: 'callboard-track-number',
				'aria-hidden': 'true',
			} ),
			$( '<span>', {
				class: 'dashicons dashicons-move callboard-drag',
				'aria-hidden': 'true',
			} ),
			$( '<input>', {
				type: 'hidden',
				name: 'callboard_order[]',
				value: id,
			} ),
			$( '<label>', {
				class: 'screen-reader-text',
				for: `callboard-title-${ id }`,
			} ).text( config.titleLabel ),
			$( '<input>', {
				type: 'text',
				class: 'regular-text callboard-track-title',
				id: `callboard-title-${ id }`,
				name: `callboard_title[${ id }]`,
				value: title,
			} ),
			$( '<span>', { class: 'callboard-len' } ).text(
				attachment.get( 'fileLength' ) || ''
			),
			$( '<span>', { class: 'callboard-track-reorder' } ).append(
				$( '<button>', {
					type: 'button',
					class: 'button-link callboard-move',
					'data-dir': -1,
					'aria-label': config.moveUp,
				} ).append(
					$( '<span>', {
						class: 'dashicons dashicons-arrow-up-alt2',
						'aria-hidden': 'true',
					} )
				),
				$( '<button>', {
					type: 'button',
					class: 'button-link callboard-move',
					'data-dir': 1,
					'aria-label': config.moveDown,
				} ).append(
					$( '<span>', {
						class: 'dashicons dashicons-arrow-down-alt2',
						'aria-hidden': 'true',
					} )
				)
			)
		);

		const editLink = attachment.get( 'editLink' );
		const actions = $( '<span>', { class: 'callboard-track-actions' } );
		if ( editLink ) {
			actions.append(
				$( '<a>', { href: editLink } ).text( config.editMedia )
			);
		}
		actions.append(
			$( '<span>', { 'aria-hidden': 'true' } ).html( '&middot;' ),
			$( '<button>', {
				type: 'button',
				class:
					'button-link button-link-delete callboard-remove-track',
			} ).text( config.remove )
		);
		controls.append( actions );
		row.append( controls, detailRow( id ) ).appendTo( list );
		updateState();
	};

	list.sortable( {
		handle: '.callboard-drag',
		axis: 'y',
		update: updateState,
	} );

	list.on( 'click', '.callboard-move', ( event ) => {
		move( $( event.currentTarget ) );
	} );

	list.on( 'click', '.callboard-remove-track', ( event ) => {
		const row = $( event.currentTarget ).closest( 'li' );
		removals.append(
			$( '<input>', {
				type: 'hidden',
				name: 'callboard_removed[]',
				value: row.data( 'id' ),
			} )
		);
		row.remove();
		updateState();
	} );

	$( '.callboard-add-tracks' ).on( 'click', () => {
		warning.prop( 'hidden', true );
		if ( frame ) {
			frame.open();
			return;
		}
		frame = wp.media( {
			title: config.frameTitle,
			button: { text: config.frameButton },
			library: { type: 'audio' },
			multiple: 'add',
		} );
		frame.on( 'select', () => {
			const unavailable = [];
			frame
				.state()
				.get( 'selection' )
				.each( ( attachment ) => {
					const parent = Number(
						attachment.get( 'uploadedTo' ) || 0
					);
					if ( parent && parent !== Number( config.postId ) ) {
						unavailable.push(
							attachment.get( 'title' ) ||
								attachment.get( 'filename' )
						);
						return;
					}
					addTrack( attachment );
				} );
			if ( unavailable.length ) {
				warning
					.prop( 'hidden', false )
					.find( 'p' )
					.text(
						config.alreadyUsed.replace(
							'%s',
							unavailable.join( ', ' )
						)
					);
			}
		} );
		frame.open();
	} );

	updateState();
} )( jQuery );
