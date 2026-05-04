( function () {
	const state = {
		table: document.getElementById( 'zoecloud-backups-table' ),
		statusText: document.getElementById( 'zoecloud-status-text' ),
		feedback: document.getElementById( 'zoecloud-feedback' ),
		jobStatus: document.getElementById( 'zoecloud-job-status' ),
		preflight: document.getElementById( 'zoecloud-preflight' ),
		createButton: document.getElementById( 'zoecloud-create-backup' ),
		includeCore: document.getElementById( 'zoecloud-include-core' ),
		uploadCloud: document.getElementById( 'zoecloud-upload-drive' ),
		restoreFilename: document.getElementById( 'zoecloud-restore-filename' ),
		restoreSearch: document.getElementById( 'zoecloud-restore-search' ),
		restoreReplace: document.getElementById( 'zoecloud-restore-replace' ),
		validateRestore: document.getElementById( 'zoecloud-validate-restore' ),
		runRestore: document.getElementById( 'zoecloud-run-restore' ),
		restoreFeedback: document.getElementById( 'zoecloud-restore-feedback' ),
		tabs: document.querySelectorAll( '[data-zoecloud-tab]' ),
		tabPanels: document.querySelectorAll( '[data-zoecloud-panel]' ),
		scheduleSelect: document.getElementById( 'zoecloud_schedule' ),
		scheduleWeekdayRow: document.getElementById( 'zoecloud_schedule_weekday_row' ),
		storageProvider: document.getElementById( 'zoecloud_storage_provider' ),
		providerFields: document.querySelectorAll( '[data-zoecloud-provider-fields]' ),
		restoreModeBtns: document.querySelectorAll( '[data-zoecloud-restore-mode]' ),
		restorePanels: document.querySelectorAll( '[data-zoecloud-restore-panel]' ),
		uploadFileInput: document.getElementById( 'zoecloud-upload-file' ),
		uploadZipButton: document.getElementById( 'zoecloud-upload-zip' ),
		uploadFeedback: document.getElementById( 'zoecloud-upload-feedback' ),
		uploadFilename: document.getElementById( 'zoecloud-upload-filename' ),
		uploadArea: document.getElementById( 'zoecloud-upload-area' ),
		restoreMode: 'existing',
		currentTempKey: '',
		jobStatusTimer: null,
		keepFinalJobStatus: false,
	};

	if ( ! state.table || ! window.zoecloudAdmin ) {
		return;
	}

	const request = async ( path, options = {} ) => {
		const response = await fetch( zoecloudAdmin.root + path, {
			method: options.method || 'GET',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': zoecloudAdmin.nonce,
			},
			body: options.body ? JSON.stringify( options.body ) : undefined,
		} );

		const data = await response.json().catch( () => ( {} ) );

		if ( ! response.ok ) {
			throw new Error( data.message || 'Request failed' );
		}

		return data;
	};

	const escapeHtml = ( value ) => String( value ?? '' ).replace(
		/[&<>"']/g,
		( char ) => ( {
			'&': '&amp;',
			'<': '&lt;',
			'>': '&gt;',
			'"': '&quot;',
			"'": '&#039;',
		} )[ char ]
	);

	const renderBackups = ( backups ) => {
		if ( ! backups.length ) {
			state.table.innerHTML = '<tr><td colspan="5">No backups yet.</td></tr>';
			if ( state.restoreFilename ) {
				state.restoreFilename.innerHTML = '<option value="">No backups available</option>';
			}
			return;
		}

		state.table.innerHTML = backups
			.map( ( backup ) => {
				const filename = backup.filename || '';
				const backupId = backup.id || filename;
				const downloadUrl = zoecloudAdmin.root + 'backup-file?filename=' + encodeURIComponent( filename ) + '&_wpnonce=' + encodeURIComponent( zoecloudAdmin.nonce );

				return `
					<tr>
						<td>${ escapeHtml( backup.created_at || '' ) }</td>
						<td>${ escapeHtml( filename ) }</td>
						<td>${ formatBytes( backup.size || 0 ) }</td>
						<td>${ escapeHtml( renderCloudStatus( backup ) ) }</td>
						<td>
							<a class="button" href="${ escapeHtml( downloadUrl ) }">Download</a>
							<button type="button" class="button zoecloud-select-restore" data-filename="${ escapeHtml( filename ) }">Select</button>
							<button type="button" class="button zoecloud-delete-backup" data-id="${ escapeHtml( backupId ) }" data-filename="${ escapeHtml( filename ) }">Delete</button>
						</td>
					</tr>
				`;
			} )
			.join( '' );

		if ( state.restoreFilename ) {
			const currentValue = state.restoreFilename.value;
			state.restoreFilename.innerHTML = backups
				.map( ( backup ) => `<option value="${ escapeHtml( backup.filename || '' ) }">${ escapeHtml( backup.filename || '' ) }</option>` )
				.join( '' );

			if ( currentValue ) {
				state.restoreFilename.value = currentValue;
			}
		}
	};

	const formatBytes = ( bytes ) => {
		if ( ! bytes ) {
			return '0 B';
		}

		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let value = Number( bytes );
		let unitIndex = 0;

		while ( value >= 1024 && unitIndex < units.length - 1 ) {
			value = value / 1024;
			unitIndex++;
		}

		return `${ value.toFixed( value >= 10 || unitIndex === 0 ? 0 : 1 ) } ${ units[ unitIndex ] }`;
	};

	const renderCloudStatus = ( backup ) => {
		if ( backup.cloud && backup.cloud.provider ) {
			return `Uploaded to ${ backup.cloud.provider.toUpperCase() }`;
		}

		if ( backup.cloud_error ) {
			return backup.cloud_error;
		}

		return 'Pending';
	};

	const renderPreflight = ( preflight ) => {
		if ( ! state.preflight || ! preflight ) {
			return;
		}

		const rows = [
			[ 'ZipArchive', preflight.ziparchive ],
			[ 'Uploads writable', preflight.uploads_writable ],
			[ 'Can create backup files', preflight.can_create_files ],
			[ 'Free disk', preflight.disk_free_bytes === null ? 'Unknown' : formatBytes( preflight.disk_free_bytes ) ],
			[ 'Memory limit', preflight.memory_limit || 'Unknown' ],
			[ 'Max execution time', preflight.max_execution_time || 'Unknown' ],
			[ 'WP-Cron disabled', preflight.wp_cron_disabled ? 'Yes' : 'No' ],
		];

		state.preflight.innerHTML = rows
			.map( ( [ label, value ] ) => `<li class="${ value === false ? 'is-error' : '' }"><strong>${ escapeHtml( label ) }:</strong> ${ escapeHtml( value === true ? 'OK' : value === false ? 'Missing' : value ) }</li>` )
			.join( '' );

		if ( state.createButton ) {
			state.createButton.disabled = ! preflight.ready;
		}
	};

	const renderJobStatus = ( job ) => {
		if ( ! state.jobStatus ) {
			return;
		}

		window.clearTimeout( state.jobStatusTimer );
		state.keepFinalJobStatus = [ 'completed', 'failed' ].includes( job.status );
		state.jobStatus.innerHTML = `
			<div class="zoecloud-progress">
				<div class="zoecloud-progress-bar" style="width: ${ Math.max( 0, Math.min( 100, Number( job.progress ) || 0 ) ) }%"></div>
			</div>
			<p>${ escapeHtml( job.message || 'Working...' ) } ${ escapeHtml( Math.max( 0, Math.min( 100, Number( job.progress ) || 0 ) ) ) }%</p>
		`;
	};

	const clearJobStatusLater = () => {
		window.clearTimeout( state.jobStatusTimer );
		state.jobStatusTimer = window.setTimeout( () => {
			state.keepFinalJobStatus = false;
			if ( state.jobStatus ) {
				state.jobStatus.innerHTML = '';
			}
		}, 6000 );
	};

	const renderJobs = ( jobs ) => {
		if ( ! state.jobStatus ) {
			return;
		}

		const active = ( jobs || [] ).find( ( job ) => [ 'queued', 'running' ].includes( job.status ) );

		if ( ! active ) {
			if ( state.keepFinalJobStatus ) {
				return;
			}
			window.clearTimeout( state.jobStatusTimer );
			state.jobStatus.innerHTML = '';
			return;
		}

		renderJobStatus( active );
	};

	const refresh = async () => {
		const status = await request( 'status' );
		const cloudLabel = status.cloud.label || ( status.cloud.provider ? status.cloud.provider.toUpperCase() : 'Cloud storage' );
		state.statusText.textContent = status.cloud.configured
			? `${ cloudLabel } configured for ${ status.cloud.bucket || 'bucket' }.`
			: 'Cloud storage not configured yet. Backups will be created locally.';
		if ( state.uploadCloud ) {
			state.uploadCloud.checked = !! status.cloud.configured;
			state.uploadCloud.disabled = ! status.cloud.configured;
		}
		renderPreflight( status.preflight );
		renderBackups( status.backups || [] );
		renderJobs( status.jobs || [] );
	};

	const setFeedback = ( message, isError = false ) => {
		state.feedback.textContent = message;
		state.feedback.classList.toggle( 'is-error', isError );
	};

	const activateTab = ( target, updateHash = true ) => {
		const tab = Array.from( state.tabs ).find( ( item ) => item.dataset.zoecloudTab === target );

		if ( ! tab ) {
			return;
		}

		state.tabs.forEach( ( item ) => item.classList.toggle( 'is-active', item === tab ) );
		state.tabPanels.forEach( ( panel ) => {
			panel.classList.toggle( 'is-active', panel.dataset.zoecloudPanel === target );
		} );

		if ( updateHash ) {
			window.history.replaceState( null, '', `#${ target }` );
		}
	};

	const updateProviderFields = () => {
		const provider = state.storageProvider?.value || 'r2';

		state.providerFields.forEach( ( fieldset ) => {
			fieldset.hidden = fieldset.dataset.zoecloudProviderFields !== provider;
		} );
	};

	const updateScheduleFields = () => {
		if ( state.scheduleWeekdayRow ) {
			state.scheduleWeekdayRow.hidden = state.scheduleSelect?.value !== 'weekly';
		}
	};

	state.tabs.forEach( ( tab ) => {
		tab.addEventListener( 'click', () => activateTab( tab.dataset.zoecloudTab ) );
	} );

	state.scheduleSelect?.addEventListener( 'change', updateScheduleFields );
	updateScheduleFields();

	state.storageProvider?.addEventListener( 'change', updateProviderFields );
	updateProviderFields();

	document.querySelectorAll( '.zoecloud-tab-panel form' ).forEach( ( form ) => {
		form.addEventListener( 'submit', () => {
			const referer = form.querySelector( 'input[name="_wp_http_referer"]' );

			if ( referer ) {
				referer.value = `${ window.location.pathname }${ window.location.search }${ window.location.hash }`;
			}
		} );
	} );

	if ( [ '#backups', '#storage' ].includes( window.location.hash ) ) {
		activateTab( window.location.hash.slice( 1 ), false );
	}

	state.createButton?.addEventListener( 'click', async () => {
		state.createButton.disabled = true;
		setFeedback( 'Queueing backup…' );

		try {
			const job = await request( 'backups', {
				method: 'POST',
				body: {
					include_core: !! state.includeCore?.checked,
					upload_drive: !! state.uploadCloud?.checked,
				},
			} );
			setFeedback( 'Backup job queued.' );
			await pollJob( job.id );
		} catch ( error ) {
			setFeedback( error.message, true );
		} finally {
			state.createButton.disabled = false;
		}
	} );

	state.table.addEventListener( 'click', ( event ) => {
		const restoreButton = event.target.closest( '.zoecloud-select-restore' );
		const deleteButton = event.target.closest( '.zoecloud-delete-backup' );

		if ( deleteButton ) {
			deleteBackup( deleteButton.dataset.id || '', deleteButton.dataset.filename || '' );
			return;
		}

		if ( ! restoreButton || ! state.restoreFilename ) {
			return;
		}

		state.restoreFilename.value = restoreButton.dataset.filename || '';
		setRestoreFeedback( `Selected ${ state.restoreFilename.value } for restore.` );
	} );

	const pollJob = async ( jobId ) => {
		if ( ! jobId ) {
			return;
		}

		let keepPolling = true;

		while ( keepPolling ) {
			let job = await request( `jobs/${ encodeURIComponent( jobId ) }` );

			if ( [ 'queued', 'running' ].includes( job.status ) ) {
				job = await request( `jobs/${ encodeURIComponent( jobId ) }/tick`, {
					method: 'POST',
				} );
			}

			renderJobStatus( job );

			if ( 'completed' === job.status ) {
				setFeedback( job.message || 'Backup completed.' );
				clearJobStatusLater();
				await refresh();
				keepPolling = false;
			} else if ( 'failed' === job.status ) {
				setFeedback( job.message || 'Backup failed.', true );
				clearJobStatusLater();
				await refresh();
				keepPolling = false;
			} else {
				await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
			}
		}
	};

	const deleteBackup = async ( backupId, filename ) => {
		if ( ! backupId ) {
			return;
		}

		if ( ! window.confirm( `Delete ${ filename || 'this backup' }?` ) ) {
			return;
		}

		try {
			await request( `backups/${ encodeURIComponent( backupId ) }`, {
				method: 'DELETE',
			} );
			setFeedback( 'Backup deleted.' );
			window.clearTimeout( state.jobStatusTimer );
			state.keepFinalJobStatus = false;
			if ( state.jobStatus ) {
				state.jobStatus.innerHTML = '';
			}
			await refresh();
		} catch ( error ) {
			setFeedback( error.message, true );
		}
	};

	const setRestoreFeedback = ( message, isError = false ) => {
		if ( ! state.restoreFeedback ) {
			return;
		}

		state.restoreFeedback.textContent = message;
		state.restoreFeedback.classList.toggle( 'is-error', isError );
	};

	const switchRestoreMode = ( mode ) => {
		state.restoreMode = mode;
		state.currentTempKey = '';

		state.restoreModeBtns.forEach( ( btn ) => {
			btn.classList.toggle( 'is-active', btn.dataset.zoecloudRestoreMode === mode );
		} );

		state.restorePanels.forEach( ( panel ) => {
			panel.hidden = panel.dataset.zoecloudRestorePanel !== mode;
		} );

		setRestoreFeedback( '' );

		if ( state.uploadFeedback ) {
			state.uploadFeedback.textContent = '';
			state.uploadFeedback.classList.remove( 'is-error' );
		}
	};

	state.restoreModeBtns.forEach( ( btn ) => {
		btn.addEventListener( 'click', () => switchRestoreMode( btn.dataset.zoecloudRestoreMode ) );
	} );

	state.uploadFileInput?.addEventListener( 'change', () => {
		const file = state.uploadFileInput.files?.[ 0 ];

		if ( state.uploadFilename ) {
			state.uploadFilename.textContent = file ? file.name : 'Choose a ZIP file or drop it here';
		}

		state.currentTempKey = '';
	} );

	state.uploadArea?.addEventListener( 'dragover', ( event ) => {
		event.preventDefault();
		state.uploadArea.classList.add( 'is-dragover' );
	} );

	state.uploadArea?.addEventListener( 'dragleave', () => {
		state.uploadArea.classList.remove( 'is-dragover' );
	} );

	state.uploadArea?.addEventListener( 'drop', ( event ) => {
		event.preventDefault();
		state.uploadArea.classList.remove( 'is-dragover' );

		const file = event.dataTransfer?.files?.[ 0 ];

		if ( file && state.uploadFileInput ) {
			const dt = new DataTransfer();
			dt.items.add( file );
			state.uploadFileInput.files = dt.files;

			if ( state.uploadFilename ) {
				state.uploadFilename.textContent = file.name;
			}

			state.currentTempKey = '';
		}
	} );

	const setUploadFeedback = ( message, isError = false ) => {
		if ( ! state.uploadFeedback ) {
			return;
		}

		state.uploadFeedback.textContent = message;
		state.uploadFeedback.classList.toggle( 'is-error', isError );
	};

	state.uploadZipButton?.addEventListener( 'click', async () => {
		const file = state.uploadFileInput?.files?.[ 0 ];

		if ( ! file ) {
			setUploadFeedback( 'Select a ZIP file first.', true );
			return;
		}

		state.uploadZipButton.disabled = true;
		setUploadFeedback( 'Uploading…' );
		state.currentTempKey = '';

		const formData = new FormData();
		formData.append( 'zip_file', file );

		try {
			const response = await fetch( zoecloudAdmin.root + 'restore/upload', {
				method: 'POST',
				headers: {
					'X-WP-Nonce': zoecloudAdmin.nonce,
				},
				body: formData,
			} );

			const data = await response.json().catch( () => ( {} ) );

			if ( ! response.ok ) {
				throw new Error( data.message || 'Upload failed' );
			}

			state.currentTempKey = data.temp_key || '';
			setUploadFeedback( `File uploaded (${ formatBytes( data.size || 0 ) }). Ready to validate or restore.` );
		} catch ( error ) {
			setUploadFeedback( error.message, true );
		} finally {
			state.uploadZipButton.disabled = false;
		}
	} );

	state.validateRestore?.addEventListener( 'click', async () => {
		let queryParam;

		if ( state.restoreMode === 'upload' ) {
			if ( ! state.currentTempKey ) {
				setRestoreFeedback( 'Upload a ZIP file first.', true );
				return;
			}

			queryParam = `temp_key=${ encodeURIComponent( state.currentTempKey ) }`;
		} else {
			const filename = state.restoreFilename?.value || '';

			if ( ! filename ) {
				setRestoreFeedback( 'Select a backup first.', true );
				return;
			}

			queryParam = `filename=${ encodeURIComponent( filename ) }`;
		}

		try {
			const plan = await request( `restore?${ queryParam }` );
			setRestoreFeedback(
				`Valid backup. Origin: ${ plan.origin_home_url || 'unknown' }. Files: ${ plan.files_count ?? 'unknown' }. Database rows: ${ plan.database_rows ?? 'unknown' }.`
			);
		} catch ( error ) {
			setRestoreFeedback( error.message, true );
		}
	} );

	state.runRestore?.addEventListener( 'click', async () => {
		let body;

		if ( state.restoreMode === 'upload' ) {
			if ( ! state.currentTempKey ) {
				setRestoreFeedback( 'Upload a ZIP file first.', true );
				return;
			}

			body = {
				temp_key: state.currentTempKey,
				search: state.restoreSearch?.value || '',
				replace: state.restoreReplace?.value || '',
				confirm: true,
			};
		} else {
			const filename = state.restoreFilename?.value || '';

			if ( ! filename ) {
				setRestoreFeedback( 'Select a backup first.', true );
				return;
			}

			body = {
				filename,
				search: state.restoreSearch?.value || '',
				replace: state.restoreReplace?.value || '',
				confirm: true,
			};
		}

		if ( ! window.confirm( 'This will overwrite files and database tables. Continue?' ) ) {
			return;
		}

		state.runRestore.disabled = true;
		setRestoreFeedback( 'Running restore…' );

		try {
			await request( 'restore', {
				method: 'POST',
				body,
			} );
			setRestoreFeedback( 'Restore completed.' );

			if ( state.restoreMode === 'upload' ) {
				state.currentTempKey = '';

				if ( state.uploadFileInput ) {
					state.uploadFileInput.value = '';
				}

				if ( state.uploadFilename ) {
					state.uploadFilename.textContent = 'Choose a ZIP file or drop it here';
				}

				setUploadFeedback( '' );
			}
		} catch ( error ) {
			setRestoreFeedback( error.message, true );
		} finally {
			state.runRestore.disabled = false;
		}
	} );

	refresh().catch( ( error ) => setFeedback( error.message, true ) );
}() );
