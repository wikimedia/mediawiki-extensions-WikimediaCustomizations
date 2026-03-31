// License: GPL-2.0-or-later
'use strict';

( function ( $, mw ) {

	const wikiTemplates = {
		// shared projects
		commonswiki: '{{WMF-legal banned user}}',
		wikidatawiki: '{{WMF-legal banned user}}',
		// wikipedias
		arwiki: '{{مستخدم مطرود لأسباب قانونية}}',
		bnwiki: '{{উইকিমিডিয়া ফাউন্ডেশন-আইনি কর্তৃক নিষিদ্ধ হওয়া ব্যবহারকারী}}',
		dewiki: '{{Global_gebannter_Benutzer}}',
		enwiki: '{{WMF-legal banned user}}',
		eswiki: '{{WMF-legal banned user}}',
		fawiki: '{{WMF-legal banned user}}',
		frwiki: '{{Utilisateur banni globalement par la Wikimedia Foundation}}',
		itwiki: '{{WMF-legal banned user}}',
		nlwiki: '{{WMF-legal banned user}}',
		ptwiki: '{{WMF-legal banned user}}',
		ruwiki: '{{WMF-legal banned user}}',
		ukwiki: '{{WMF-legal banned user}}',
		zhwiki: '{{WMF-legal banned user}}',
		// wiktionaries
		enwiktionary: '{{WMF-legal banned user}}'
	};

	const defaultBanTemplate = '__NOINDEX__ <table style="border: 1px solid #aaa; margin: 4px 10%; border-collapse: collapse; background: #f9f9f9;" class="plainlinks" role="presentation"><tr><td style="border:none; padding:2px 0 2px 0.9em;">[[File:Wikimedia Foundation logo - vertical.svg|45px|alt=Wikimedia Foundation Logo]]</td><td style="border:none; padding: 0.25em 0.9em; text-align:center;">\'\'\'Consistent with the Terms of Use, {{#ifexpr:floor({{NAMESPACENUMBER}}/2)=1|{{BASEPAGENAME}}|this user}} has been banned by the Wikimedia Foundation from editing Wikimedia sites.\'\'\' <br /> Please address any questions to ca[[File:At sign.svg|x15px|middle|link=|alt=@]]wikimedia.org.</td></tr></table> {{#ifeq:{{NAMESPACENUMBER}}|3|[[Category:Opted-out of message delivery]]}}[[Category:Wikimedians banned by the WMF]]';

	let $log;

	function report( text ) {
		$log.append(
			$( '<p>' ).css( 'color', '#d33' ).css( 'font-weight', 'bold' ).text( text )
		);
	}

	function escapeRegExp( string ) {
		return string.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	}

	/**
	 * Attempt to log in to a foreign wiki to verify the session is active there.
	 * Returns a rejected promise if the user is not logged in on that wiki.
	 *
	 * @param {string} apiUrl
	 * @return {jQuery.Promise}
	 */
	function loginToWiki( apiUrl ) {
		const api = new mw.ForeignApi( apiUrl );
		return api.get( {
			action: 'query',
			meta: 'userinfo',
			assert: 'user'
		} );
	}

	/**
	 * Handle per-wiki tasks: remove from mentorship program, replace user/talk pages,
	 * and remove local user rights (via meta's API using the global username@dbname syntax).
	 *
	 * @param {string} apiUrl
	 * @param {string} username
	 * @param {string} dbname
	 * @return {Promise}
	 */
	async function handleWiki( apiUrl, username, dbname ) {
		const api = new mw.ForeignApi( apiUrl );

		try {
			await api.get( {
				action: 'query',
				meta: 'userinfo',
				assert: 'user'
			} );
		} catch ( _error ) {
			report( 'You are not logged in on ' + dbname + '. Skipping.' );
			return;
		}

		// Remove from mentorship program (GrowthExperiments) if available
		report( 'Removing from mentorship program on ' + dbname + '...' );
		api.get( {
			action: 'growthmanagementorlist',
			assert: 'user',
			geaction: 'remove',
			summary: 'Remove globally banned user from mentorship list',
			username: username
		} ).then( () => {
			report( 'Removed from mentorship program on ' + dbname + '.' );
		} );

		// Replace user page and user talk page if they exist (skip on metawiki,
		// which is handled separately in carryOutTheBan)
		if ( dbname !== 'metawiki' ) {
			const banText = wikiTemplates[ dbname ] || defaultBanTemplate;

			api.get( {
				action: 'query',
				assert: 'user',
				prop: 'revisions',
				titles: 'User:' + username,
				rvprop: 'user'
			} ).done( ( data ) => {
				if ( !data.query.pages[ '-1' ] ) {
					api.edit( 'User:' + username, () => ( {
						text: banText,
						summary: 'This user has been globally banned'
					} ) ).then( () => {
						report( 'User page replaced on ' + dbname + '.' );
					} );
				}
			} );

			api.get( {
				action: 'query',
				assert: 'user',
				prop: 'revisions',
				titles: 'User talk:' + username,
				rvprop: 'user'
			} ).done( ( data ) => {
				if ( !data.query.pages[ '-1' ] ) {
					api.edit( 'User talk:' + username, () => ( {
						text: banText,
						summary: 'This user has been globally banned'
					} ) ).then( () => {
						report( 'User talk page replaced on ' + dbname + '.' );
					} );
				}
			} );
		}

		// Remove local user rights via meta's API (username@dbname syntax)
		api.get( {
			action: 'query',
			assert: 'user',
			list: 'users',
			ususers: username,
			usprop: 'groups'
		} ).done( ( data ) => {
			const groups = ( data.query.users[ 0 ].groups || [] )
				.filter( ( g ) => g !== '*' && g !== 'user' );
			if ( groups.length ) {
				const metaApi = new mw.Api();
				metaApi.postWithToken( 'userrights', {
					action: 'userrights',
					user: username + '@' + dbname,
					remove: groups.join( '|' ),
					reason: 'WMF banned user'
				} ).then( () => {
					report( 'Local rights removed on ' + dbname + ': ' + groups.join( ', ' ) );
				} );
			}
		} );
	}

	/**
	 * Main ban function. Orchestrates all steps:
	 * 1. Lock global account (unlock first if already locked, to refresh the lock reason)
	 * 2. Replace meta user page and user talk page
	 * 3. Remove global rights
	 * 4. Fan out to per-wiki tasks (rights removal, page replacement, mentorship removal)
	 * 5. Add to WMF Global Ban Policy/List on meta
	 * 6. Remove from NDA noticeboard on meta
	 *
	 * @param {string} username
	 */
	function carryOutTheBan( username ) {
		username = username.trim();

		if ( !username ) {
			report( 'Username is empty. Aborting.' );
			return;
		}

		// eslint-disable-next-line no-alert
		if ( !confirm( 'You are about to office ban ' + username + '. Are you sure?' ) ) {
			report( 'Confirmation cancelled. Aborting.' );
			return;
		}

		report( 'Starting the office ban...' );

		const api = new mw.Api();

		// Step 1: Lock the global account.
		// If already locked, unlock first so the lock reason is refreshed.
		report( 'Locking account...' );
		api.get( {
			action: 'query',
			meta: 'globaluserinfo',
			guiuser: username,
			guiprop: 'groups',
			formatversion: '2'
		} ).done( ( data ) => {
			const lockAndReport = function () {
				api.postWithToken( 'setglobalaccountstatus', {
					action: 'setglobalaccountstatus',
					locked: 'lock',
					user: username,
					reason: 'Re-lock - Globally or WMF banned user: [[m:WMF Global Ban Policy|Foundation Global Ban]] - do not reinstate. Questions can be directed to ca@wikimedia.org'
				} ).then( () => {
					report( 'Account locked.' );
				} );
			};

			if ( data.query.globaluserinfo.locked ) {
				api.postWithToken( 'setglobalaccountstatus', {
					action: 'setglobalaccountstatus',
					locked: 'unlock',
					user: username,
					reason: 'Switching to [[WMF Global Ban Policy|WMF Global Ban]]'
				} ).then( () => {
					report( 'Account unlocked; re-locking with updated reason...' );
					lockAndReport();
				} );
			} else {
				lockAndReport();
			}
		} );

		// Step 2: Replace meta user page
		report( 'Replacing meta user page...' );
		api.get( {
			action: 'query',
			assert: 'user',
			prop: 'revisions',
			titles: 'User:' + username,
			rvprop: 'user'
		} ).done( ( data ) => {
			if ( !data.query.pages[ '-1' ] ) {
				api.edit( 'User:' + username, () => ( {
					text: '{{WMF-legal banned user}}',
					summary: 'This user has been globally banned'
				} ) ).then( () => {
					report( 'Meta user page replaced.' );
				} );
			} else {
				api.create(
					'User:' + username,
					{ summary: 'This user has been globally banned' },
					'{{WMF-legal banned user}}'
				).then( () => {
					report( 'Meta user page created.' );
				} );
			}
		} );

		// Step 3: Replace meta user talk page
		report( 'Replacing meta user talk page...' );
		api.get( {
			action: 'query',
			assert: 'user',
			prop: 'revisions',
			titles: 'User talk:' + username,
			rvprop: 'user'
		} ).done( ( data ) => {
			if ( !data.query.pages[ '-1' ] ) {
				api.edit( 'User talk:' + username, () => ( {
					text: '{{WMF-legal banned user}}',
					summary: 'This user has been globally banned'
				} ) ).then( () => {
					report( 'Meta user talk page replaced.' );
				} );
			} else {
				api.create(
					'User talk:' + username,
					{ summary: 'This user has been globally banned' },
					'{{WMF-legal banned user}}'
				).then( () => {
					report( 'Meta user talk page created.' );
				} );
			}
		} );

		// Steps 4+: Fan out to other wikis using globaluserinfo to determine which
		// wikis to handle (top wikis by edit count + any wiki where the user holds local rights)
		api.get( {
			action: 'query',
			meta: 'globaluserinfo',
			guiuser: username,
			guiprop: 'groups|merged|editcount'
		} ).done( ( data ) => {

			// Step 4a: Remove global rights
			const globalGroups = ( data.query.globaluserinfo.groups || [] )
				.filter( ( g ) => g !== '*' && g !== 'user' );
			if ( globalGroups.length ) {
				api.post( {
					action: 'globaluserrights',
					user: username,
					remove: globalGroups.join( '|' ),
					reason: 'WMF banned user'
				} ).then( () => {
					report( 'Global rights removed: ' + globalGroups.join( ', ' ) );
				} );
			}

			// Step 4b: Build the list of wikis to handle.
			// Include top wikis by edit count and any wiki where the user holds local rights.
			const merged = data.query.globaluserinfo.merged || [];
			const topWikis = merged
				.slice()
				.sort( ( a, b ) => b.editcount - a.editcount )
				.map( ( i ) => i.wiki );
			const wikisWithRights = merged
				.filter( ( i ) => i.groups && i.groups.length )
				.map( ( i ) => i.wiki );
			const wikisToHandle = [ ...new Set( [ ...topWikis, ...wikisWithRights ] ) ];

			// Step 4c: Fetch the sitematrix to resolve dbname -> API URL, then dispatch.
			api.get( {
				action: 'sitematrix',
				smsiteprop: 'dbname|url'
			} ).done( ( siteData ) => {
				// Special wikis (commons, wikidata, meta, etc.)
				siteData.sitematrix.specials.forEach( ( site ) => {
					if ( wikisToHandle.includes( site.dbname ) ) {
						report( 'Handling ' + site.dbname + '...' );
						handleWiki( site.url + '/w/api.php', username, site.dbname );
					}
				} );

				// Language wikis. Sitematrix returns a numeric-keyed object, not an array.
				for ( const key in siteData.sitematrix ) {
					if ( String( Number( key ) ) !== key ) {
						continue;
					}
					( siteData.sitematrix[ key ].site || [] ).forEach( ( site ) => {
						if ( wikisToHandle.includes( site.dbname ) ) {
							report( 'Handling ' + site.dbname + '...' );
							loginToWiki( site.url + '/w/api.php' ).then( () => {
								handleWiki( site.url + '/w/api.php', username, site.dbname );
							} );
						}
					} );
				}
			} );
		} );

		// Step 5: Add to WMF Global Ban Policy/List on meta
		report( 'Adding to global ban list...' );
		api.edit( 'WMF Global Ban Policy/List', ( revision ) => {
			let content = revision.content;
			const date = new Date();
			const month = new Intl.DateTimeFormat( 'en-US', { month: 'long' } ).format( date );
			content = content.replace( '\n{{div col end}}', '' );
			content += '\n* [[m:Special:CentralAuth/' + username + '|' + username + ']], since ' +
				date.getDate() + ' ' + month + ' ' + date.getFullYear();
			content += '\n{{div col end}}';
			return {
				text: content,
				summary: '+ ' + username
			};
		} ).then( () => {
			report( 'Added to the global ban list.' );
		} );

		// Step 6: Remove from meta NDA noticeboard
		report( 'Removing from NDA noticeboard...' );
		api.edit( 'Access to nonpublic personal data policy/Noticeboard', ( revision ) => {
			let content = revision.content;
			const reg = new RegExp( '\n\\{\\{\\/user\\|\\d+\\|' + escapeRegExp( username ) + '}}' );
			content = content.replace( reg, '' );
			return {
				text: content,
				summary: 'Strike globally banned user'
			};
		} ).then( () => {
			report( 'Removed from NDA noticeboard.' );
		} );
	}

	function loadForm() {
		$log = $( '<div>' ).attr( 'id', 'office-ban-log' );

		const $input = $( '<div>' )
			.addClass( 'cdx-text-input' )
			.append(
				$( '<input>' )
					.attr( {
						id: 'office-ban-username',
						type: 'text',
						placeholder: 'Username (without User: prefix)'
					} )
					.addClass( 'cdx-text-input__input' )
			);

		const $button = $( '<button>' )
			.addClass( 'cdx-button cdx-button--action-destructive cdx-button--weight-primary' )
			.text( 'Ban user' );

		function submitBan() {
			$button.prop( 'disabled', true );
			carryOutTheBan( document.getElementById( 'office-ban-username' ).value );
		}

		$input.find( 'input' ).on( 'keyup', ( event ) => {
			if ( event.key === 'Enter' ) {
				submitBan();
			}
		} );

		$button.on( 'click', submitBan );

		const $container = $( '<div>' )
			.css( { 'max-width': '300px', display: 'grid', 'row-gap': '10px' } )
			.append( $input, $button );

		$( '.mw-body-content' ).html( $container ).append( $log );
	}

	mw.loader.using( 'codex-styles' ).then( () => {
		loadForm();
	} );

}( jQuery, mediaWiki ) );
