<?php

/** Ctrl+K (Cmd+K) opens a centered quick search over the tables of the current database
* Enter opens the table data, Shift+Enter its structure, Ctrl+Enter a new tab.
* Prefixes: @ databases, # column names, > queries saved from the SQL command page.
* Words match in any order; an empty search lists pinned (Alt+P) and recently opened tables first.
* @author Ahmad Pausi
* @license https://opensource.org/licenses/MIT MIT License
*/
class AdminerTableSearch extends Adminer\Plugin {
	// ?table-search=rows answers with row counts (loaded when the dialog opens),
	// ?table-search=columns with column names (loaded only once # is typed, it can be big)
	function headers() {
		if (!isset($_GET["table-search"]) || Adminer\DB == "" || !Adminer\connection()) {
			return;
		}
		$columns = ($_GET["table-search"] == "columns");
		$return = array();
		if (Adminer\JUSH == "sql") {
			// one query instead of a call per table, which is slow on remote servers
			$query = ($columns
				? "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = " . Adminer\q(Adminer\DB) . " ORDER BY TABLE_NAME, ORDINAL_POSITION"
				: "SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . Adminer\q(Adminer\DB)
			);
			foreach (Adminer\get_rows($query) as $row) {
				if ($columns) {
					$return[$row["TABLE_NAME"]][] = $row["COLUMN_NAME"];
				} else {
					$return[$row["TABLE_NAME"]] = $row["TABLE_ROWS"];
				}
			}
		} else {
			Adminer\connection()->select_db(Adminer\DB);
			foreach (Adminer\table_status('') as $name => $status) {
				$return[$name] = ($columns ? array_keys(Adminer\fields($name)) : $status["Rows"]);
			}
		}
		header("Content-Type: application/json; charset=utf-8");
		echo json_encode((object) $return);
		exit;
	}

	// tablesPrint only runs when a database with tables is selected, so the list is handed over separately
	function tablesPrint($tables) {
		$list = array();
		$views = array();
		foreach ($tables as $table => $status) {
			$list[] = (string) $table;
			if (Adminer\is_view($status)) {
				$views[] = (string) $table;
			}
		}
		echo Adminer\script("tableSearchTables = " . json_encode($list) . "; tableSearchViews = " . json_encode($views) . ";");
	}

	// navigation runs on every page, so the shortcut is always captured
	function navigation($missing) {
		if ($missing == "auth") {
			return;
		}
		?>
<style>
#table-search { width: min(36rem, calc(100vw - 32px)); padding: 0; border: 1px solid #999; border-radius: 6px; box-shadow: 0 8px 32px rgba(0,0,0,.35); }
#table-search::backdrop { background: rgba(0,0,0,.4); }
#table-search input { box-sizing: border-box; width: 100%; padding: .6em .8em; font-size: 1.1em; border: 0; border-bottom: 1px solid #999; outline: 0; background: transparent; color: inherit; }
#table-search ul { list-style: none; margin: 0; padding: 0; max-height: 60vh; overflow: auto; }
#table-search li { display: flex; justify-content: space-between; align-items: center; gap: .5em; padding: .4em .8em; cursor: pointer; }
#table-search li > span { flex: 1; min-width: 0; }
#table-search li small { display: block; opacity: .6; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
#table-search li small.rows { flex: none; min-width: 5.5em; text-align: right; font-variant-numeric: tabular-nums; }
#table-search li em { font-style: normal; font-size: .75em; opacity: .65; margin-left: .6em; padding: 0 .35em; border: 1px solid; border-radius: 3px; vertical-align: .1em; }
#table-search li button { border: 0; background: none; padding: 0 .2em; cursor: pointer; opacity: 0; filter: grayscale(1); color: inherit; }
#table-search li.active button, #table-search li button:hover { opacity: .5; }
#table-search li button.pinned { opacity: 1; filter: none; }
#table-search li.active { background: rgba(127,127,127,.3); font-weight: normal; } /* Adminer makes .active bold, hiding the matched parts */
#table-search li.empty { cursor: default; opacity: .6; }
</style>
<dialog id="table-search" aria-label="<?php echo Adminer\h($this->lang('Search tables')); ?>"><input type="search" autocomplete="off" placeholder="<?php echo Adminer\h($this->lang('Search tables (@ databases, # columns, > saved queries)')); ?>…" role="combobox" aria-controls="table-search-list"><ul id="table-search-list" role="listbox"></ul></dialog>
<script<?php echo Adminer\nonce(); ?>>
(() => {
	const me = <?php echo json_encode(Adminer\ME); ?>;
	const hasDb = <?php echo json_encode(Adminer\DB != ""); ?>;
	const text = <?php echo json_encode(array(
		'recent' => $this->lang('recent'),
		'view' => $this->lang('view'),
		'rows' => $this->lang('Estimated number of rows'),
		'pin' => $this->lang('Pin'),
		'unpin' => $this->lang('Unpin'),
		'delete' => $this->lang('Delete saved query'),
		'noTables' => $this->lang(Adminer\DB == "" ? 'Select a database first, or type @ to search databases.' : 'No tables.'),
		'noDatabases' => $this->lang('No databases.'),
		'noColumns' => $this->lang('No column matches.'),
		'noQueries' => $this->lang('No saved queries. Save one with the button next to Execute on the SQL command page.'),
		'loading' => $this->lang('Loading…'),
		'loadError' => $this->lang('Columns could not be loaded.'),
		'save' => $this->lang('Save query'),
		'saved' => $this->lang('Saved'),
		'name' => $this->lang('Name of the saved query:'),
	)); ?>;
	const dialog = qs('#table-search');
	const input = qs('input', dialog);
	const list = qs('ul', dialog);
	// me holds server, username and db, so everything is kept per database
	const pinKey = 'adminer_table_search_pins:' + me;
	const recentKey = 'adminer_table_search_recent:' + me;
	const queryKey = 'adminer_saved_queries:' + me;
	// Adminer 5 urlencoded every value of me (server=localhost%3A3307), Adminer 6 escapes only what is necessary
	const legacyMe = me.replace(/[^?&=]+/g, s => encodeURIComponent(decodeURIComponent(s)).replace(/[!'()*~]/g, c => '%' + c.charCodeAt(0).toString(16).toUpperCase()).replace(/%20/g, '+'));
	const load = key => {
		try {
			// data saved under Adminer 5 is read until the first save moves it to the current key
			return JSON.parse(localStorage.getItem(key) ?? localStorage.getItem(key.slice(0, -me.length) + legacyMe)) || [];
		} catch (e) {
			return [];
		}
	};
	const save = (key, value) => {
		try {
			localStorage.setItem(key, JSON.stringify(value));
		} catch (e) {
		}
	};
	// data.rows and data.columns: undefined until requested, then 'loading', 'error' or the loaded object
	let items = [], active = 0, data = {}, pins = load(pinKey), recent = load(recentKey), queries = load(queryKey);

	// remember the table opened on this page, most recent first
	const params = new URLSearchParams(location.search);
	const current = params.get('select') ?? params.get('table');
	if (current) {
		recent = [current].concat(recent.filter(t => t != current)).slice(0, 10);
		save(recentKey, recent);
	}

	function loadData(kind) {
		if (data[kind] || !hasDb) {
			return;
		}
		data[kind] = 'loading';
		fetch(me + 'table-search=' + kind).then(response => response.json()).then(result => {
			data[kind] = result;
		}, () => {
			data[kind] = 'error';
		}).then(() => dialog.open && render(items[active]?.name));
	}

	const loaded = kind => (typeof data[kind] == 'object' ? data[kind] : null);

	function togglePin(table) {
		pins = (pins.includes(table) ? pins.filter(t => t != table) : pins.concat(table));
		save(pinKey, pins);
		render(table);
	}

	function deleteQuery(name) {
		queries = queries.filter(query => query.name != name);
		save(queryKey, queries);
		render();
	}

	// text with the parts matching any of the words in bold
	function marked(name, words, tag = 'span') {
		const bold = [];
		const lower = name.toLowerCase();
		for (const word of words) {
			for (let i = lower.indexOf(word); i != -1; i = lower.indexOf(word, i + 1)) {
				for (let j = i; j < i + word.length; j++) {
					bold[j] = true;
				}
			}
		}
		const el = document.createElement(tag);
		for (let i = 0; i < name.length; ) {
			let j = i;
			while (j < name.length && !!bold[j] == !!bold[i]) {
				j++;
			}
			const part = document.createTextNode(name.slice(i, j));
			if (bold[i]) {
				const strong = document.createElement('strong');
				strong.appendChild(part);
				el.appendChild(strong);
			} else {
				el.appendChild(part);
			}
			i = j;
		}
		return el;
	}

	function tableUrl(table, structure) {
		return me + (structure ? 'table=' : 'select=') + encodeURIComponent(table);
	}

	// badges go next to the name, the row count into its own right-aligned column
	function tableInfo(table) {
		const rows = loaded('rows')?.[table];
		return {
			badges: [
				(!pins.includes(table) && recent.includes(table) ? text.recent : ''),
				((window.tableSearchViews || []).includes(table) ? text.view : ''),
			].filter(s => s),
			rows: (rows != null ? '~' + (+rows).toLocaleString() : ''),
		};
	}

	// each mode gives {name, url(structure), sort, detail?, info?, button?}
	function search(prefix, words) {
		const has = s => words.every(word => s.toLowerCase().includes(word));
		const tables = window.tableSearchTables || [];
		if (prefix == '@') {
			return [...qsa('#dbs select[name=db] option')].map(option => option.value).filter(db => db && has(db)).map(db => ({
				name: db,
				sort: db,
				url: () => {
					const params = new URLSearchParams(me.slice(1));
					params.delete('ns'); // schema of the current database
					params.set('db', db);
					return '?' + params;
				},
			}));
		}
		if (prefix == '>') {
			return queries.filter(query => has(query.name + ' ' + query.sql)).map(query => ({
				name: query.name,
				sort: query.name,
				detail: query.sql.replace(/\s+/g, ' '),
				url: () => me + 'sql=' + encodeURIComponent(query.sql), // fills the editor, does not run it
				button: ['✕', text.delete, () => deleteQuery(query.name)],
			}));
		}
		if (prefix == '#') {
			const all = loaded('columns');
			if (!all) { // no database selected or loading failed
				return [];
			}
			// every word has to appear in one column name
			return tables.filter(t => (all[t] || []).some(has)).map(t => {
				const columns = all[t].filter(has);
				return {name: t, sort: columns[0], detail: columns.join(', '), ...tableInfo(t), url: structure => tableUrl(t, structure), nameWords: []};
			});
		}
		return tables.filter(has).map(t => ({
			name: t,
			sort: t,
			...tableInfo(t),
			url: structure => tableUrl(t, structure),
			button: ['📌', (pins.includes(t) ? text.unpin : text.pin) + ' (Alt+P)', () => togglePin(t), pins.includes(t)],
			pinned: pins.includes(t),
		}));
	}

	function render(keep) {
		const prefix = (/^[@#>]/.test(input.value) ? input.value[0] : '');
		const words = input.value.slice(prefix.length).toLowerCase().split(/\s+/).filter(word => word);
		if (prefix == '#' && hasDb && (!data.columns || data.columns == 'loading')) {
			loadData('columns');
			list.innerHTML = '<li class="empty"></li>';
			list.firstChild.textContent = text.loading;
			items = [];
			return;
		}
		// with a query: matches starting with the first word go first; without it: pins, then recent tables
		const rank = item => (words.length ? (item.sort.toLowerCase().startsWith(words[0]) ? 0 : 1)
			: (prefix ? 0 : (item.pinned ? -1 : (recent.includes(item.name) ? recent.indexOf(item.name) : 10))));
		items = search(prefix, words).sort((a, b) => rank(a) - rank(b));
		list.textContent = '';
		items.slice(0, 100).forEach((item, i) => {
			const li = document.createElement('li');
			li.setAttribute('role', 'option');
			li.onmousedown = event => go(item, event.shiftKey, event.ctrlKey || event.metaKey || event.button == 1);
			li.onmousemove = () => highlight(i);
			const main = marked(item.name, item.nameWords || words);
			for (const badge of item.badges || []) {
				const em = document.createElement('em');
				em.textContent = badge;
				main.appendChild(em);
			}
			if (item.detail) {
				main.appendChild(marked(item.detail, words, 'small'));
			}
			li.appendChild(main);
			if (item.rows !== undefined) {
				const rows = document.createElement('small');
				rows.className = 'rows';
				rows.textContent = item.rows;
				rows.title = text.rows;
				li.appendChild(rows);
			}
			if (item.button) {
				const [label, title, action, on] = item.button;
				const button = document.createElement('button');
				button.type = 'button';
				button.tabIndex = -1;
				button.textContent = label;
				button.title = title;
				button.className = (on ? 'pinned' : '');
				button.onmousedown = event => {
					event.stopPropagation();
					event.preventDefault(); // keep focus in the input
					action();
				};
				li.appendChild(button);
			}
			list.appendChild(li);
		});
		if (!items.length) {
			list.innerHTML = '<li class="empty"></li>';
			list.firstChild.textContent = {
				'@': text.noDatabases,
				'#': (data.columns == 'error' ? text.loadError : (hasDb ? text.noColumns : text.noTables)),
				'>': text.noQueries,
			}[prefix] || text.noTables;
		}
		highlight(keep ? Math.max(items.findIndex(item => item.name == keep), 0) : 0);
	}

	function highlight(i) {
		const lis = qsa('li[role=option]', list);
		if (!lis.length) {
			return;
		}
		active = (i + lis.length) % lis.length;
		lis.forEach((li, j) => li.classList.toggle('active', j == active));
		lis[active].scrollIntoView({block: 'nearest'});
	}

	function go(item, structure, newTab) {
		const url = item.url(structure);
		if (newTab) {
			window.open(url, '_blank');
			dialog.close();
		} else {
			location.href = url;
		}
	}

	input.oninput = () => render();
	input.onkeydown = event => {
		event.stopPropagation(); // Adminer's bodyKeydown would try to submit a form on Ctrl+Enter
		if (event.key == 'ArrowDown' || event.key == 'ArrowUp') {
			highlight(active + (event.key == 'ArrowDown' ? 1 : -1));
			event.preventDefault();
		} else if (event.altKey && event.key.toLowerCase() == 'p' && items[active]?.pinned !== undefined) {
			togglePin(items[active].name);
			event.preventDefault();
		} else if (event.key == 'Enter' && items.length) {
			go(items[active], event.shiftKey, event.ctrlKey || event.metaKey);
		}
	};
	dialog.onclick = event => event.target == dialog && dialog.close(); // click on backdrop
	// capture on window: Adminer's body onkeydown (bodyKeydown) stops propagation of every keydown
	window.addEventListener('keydown', event => {
		if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() == 'k') {
			event.preventDefault();
			if (!dialog.open) {
				// follow the active design (light/dark)
				const bg = getComputedStyle(document.body).backgroundColor;
				dialog.style.background = (bg == 'rgba(0, 0, 0, 0)' ? 'Canvas' : bg);
				dialog.style.color = getComputedStyle(document.body).color;
				input.value = '';
				loadData('rows'); // columns wait for #
				render();
				dialog.showModal();
			}
		}
	}, true);

	// SQL command page: save the query in the editor for > in the search
	const textarea = qs('textarea[name="query"]');
	const execute = textarea && qs('input[type="submit"]', textarea.form);
	if (params.has('sql') && execute) {
		const button = document.createElement('input');
		button.type = 'button';
		button.value = text.save;
		button.onclick = () => {
			const sql = (qs('.CodeMirror')?.CodeMirror?.getValue() ?? textarea.value).trim(); // CodeMirror does not sync the textarea before submit
			if (!sql) {
				return;
			}
			const name = prompt(text.name, sql.split('\n')[0].slice(0, 60));
			if (name) {
				queries = [{name, sql}].concat(queries.filter(query => query.name != name));
				save(queryKey, queries);
				button.value = text.saved + ' ✓';
				setTimeout(() => button.value = text.save, 1500);
			}
		};
		execute.after(' ', button);
	}
})();
</script>
<?php
	}

	protected $translations = array(
		'id' => array(
			'' => 'Ctrl+K untuk mencari tabel, kolom, database, dan query tersimpan',
			'Search tables' => 'Cari tabel',
			'Search tables (@ databases, # columns, > saved queries)' => 'Cari tabel (@ database, # kolom, > query tersimpan)',
			'No tables.' => 'Tidak ada tabel.',
			'Select a database first, or type @ to search databases.' => 'Pilih database terlebih dahulu, atau ketik @ untuk mencari database.',
			'No databases.' => 'Tidak ada database.',
			'No column matches.' => 'Tidak ada kolom yang cocok.',
			'No saved queries. Save one with the button next to Execute on the SQL command page.' => 'Belum ada query tersimpan. Simpan lewat tombol di samping Execute di halaman SQL command.',
			'Loading…' => 'Memuat…',
			'Columns could not be loaded.' => 'Kolom gagal dimuat.',
			'Pin' => 'Sematkan',
			'Unpin' => 'Lepas sematan',
			'recent' => 'terakhir dibuka',
			'view' => 'view',
			'Estimated number of rows' => 'Perkiraan jumlah baris',
			'Delete saved query' => 'Hapus query tersimpan',
			'Save query' => 'Simpan query',
			'Saved' => 'Tersimpan',
			'Name of the saved query:' => 'Nama query yang disimpan:',
		),
	);
}
