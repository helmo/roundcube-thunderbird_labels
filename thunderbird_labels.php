<?php
/**
 * Thunderbird Labels Plugin for Roundcube Webmail
 *
 * Plugin to show the 5 Message Labels Thunderbird Email-Client provides for IMAP
 *
 * @version 1.2.0
 * @author Michael Kefeder
 * @url https://github.com/mike-kfed/roundcube-thunderbird_labels
 */
class thunderbird_labels extends rcube_plugin
{
	public $task = 'mail|settings';
	private $rc;
	private $map;
	private $_custom_flags_allowed = null;

	function init()
	{
		$this->rc = rcmail::get_instance();
		$this->load_config();
		$this->add_texts('localization/', false);

		$this->setCustomLabels();

		if ($this->rc->task == 'mail')
		{
			# -- disable plugin when printing message
			if ($this->rc->action == 'print')
				return;

			if (!$this->rc->config->get('tb_label_enable'))
			// disable plugin according to prefs
				return;

			// pass 'tb_label_enable_shortcuts' and 'tb_label_style' prefs to JS
			$this->rc->output->set_env('tb_label_enable_shortcuts', $this->rc->config->get('tb_label_enable_shortcuts'));
			$this->rc->output->set_env('tb_label_style', $this->rc->config->get('tb_label_style'));

			$this->include_script('tb_label.js');
			$this->add_hook('messages_list', array($this, 'read_flags'));
			$this->add_hook('message_load', array($this, 'read_single_flags'));
			$this->add_hook('template_object_messageheaders', array($this, 'color_headers'));
			$this->add_hook('render_page', array($this, 'tb_label_popup'));
			$this->add_hook('check_recent', array($this, 'check_recent_flags'));
			$this->include_stylesheet($this->local_skin_path() . '/tb_label.css');
			#$this->include_stylesheet($this->local_skin_path() . '/tb_label.php');

			$this->name = get_class($this);
			# -- additional TB flags
			$this->add_tb_flags = array(
				/*'LABEL1' => '$Label1',
				'LABEL2' => '$Label2',
				'LABEL3' => '$Label3',
				'LABEL4' => '$Label4',
				'LABEL5' => '$Label5',*/
			);
			$this->message_tb_labels = array();

			$this->add_button(
				array(
					'command' => 'plugin.thunderbird_labels.rcm_tb_label_submenu',
					'id' => 'tb_label_popuplink',
					'title' => 'tb_label_button_title',
					'domain' => $this->ID,
					'type' => 'link',
					'content' => $this->gettext('tb_label_button_label'),
					'class' => 'button buttonPas disabled',
					'classact' => 'button',
					),
				'toolbar'
			);

			// JS function "set_flags" => PHP function "set_flags"
			$this->register_action('plugin.thunderbird_labels.set_flags', array($this, 'set_flags'));

			if (method_exists($this, 'require_plugin')
				&& in_array('contextmenu', $this->rc->config->get('plugins'))
				&& $this->require_plugin('contextmenu')
				&& $this->rc->config->get('tb_label_enable_contextmenu'))
			{
				if ($this->rc->action == '')
					$this->add_hook('render_mailboxlist', array($this, 'show_tb_label_contextmenu'));
			}
		}
		elseif ($this->rc->task == 'settings')
		{
			$this->include_stylesheet($this->local_skin_path() . '/tb_label.css');
			$this->add_hook('preferences_list', array($this, 'prefs_list'));
			$this->add_hook('preferences_sections_list', array($this, 'prefs_section'));
			$this->add_hook('preferences_save', array($this, 'prefs_save'));
		}
	}

	private function setCustomLabels()
	{
		$c = $this->rc->config->get('tb_label_custom_labels');
		if (empty($c) || isset($c[3]))
		{
			// if no user specific labels, use localized strings by default
			$this->rc->config->set('tb_label_custom_labels', array(
				'LABEL0' => $this->getText('label0'),
				'LABEL1' => $this->getText('label1'),
				'LABEL2' => $this->getText('label2'),
				'LABEL3' => $this->getText('label3'),
				'LABEL4' => $this->getText('label4'),
				'LABEL5' => $this->getText('label5')
			));
		}
		// pass label strings to JS
		$this->rc->output->set_env('tb_label_custom_labels', $this->rc->config->get('tb_label_custom_labels'));
	}

	// create a section for the tb-labels Settings
	public function prefs_section($args)
	{
		$args['list']['thunderbird_labels'] = array(
			'id' => 'thunderbird_labels',
			'section' => rcube::Q($this->gettext('tb_label_options'))
		);

		return $args;
	}

	// display thunderbird-labels prefs in Roundcube Settings
	public function prefs_list($args)
	{
		if ($args['section'] != 'thunderbird_labels')
			return $args;

		$this->load_config();
		$dont_override = (array) $this->rc->config->get('dont_override', array());

		$args['blocks']['tb_label'] = array();
		$args['blocks']['tb_label']['name'] = $this->gettext('tb_label_options');

		$key = 'tb_label_enable';
		if (!in_array($key, $dont_override))
		{
			$input = new html_checkbox(array(
				'name' => $key,
				'id' => $key,
				'value' => 1
			));
			$content = $input->show($this->rc->config->get($key));
			$args['blocks']['tb_label']['options'][$key] = array(
				'title' => $this->gettext('tb_label_enable_option'),
				'content' => $content
			);
		}

		$key = 'tb_label_enable_shortcuts';
		if (!in_array($key, $dont_override))
		{
			$input = new html_checkbox(array(
				'name' => $key,
				'id' => $key,
				'value' => 1
			));
			$content = $input->show($this->rc->config->get($key));
			$args['blocks']['tb_label']['options'][$key] = array(
				'title' => $this->gettext('tb_label_enable_shortcuts_option'),
				'content' => $content
			);
		}

		$key = 'tb_label_style';
		if (!in_array($key, $dont_override))
		{
			$select = new html_select(array(
				'name' => $key,
				'id' => $key
			));
			$select->add(array($this->gettext('thunderbird'), $this->gettext('bullets')), array('thunderbird', 'bullets'));
			$content = $select->show($this->rc->config->get($key));

			$args['blocks']['tb_label']['options'][$key] = array(
				'title' => $this->gettext('tb_label_style_option'),
				'content' => $content
			);
		}

		$key = 'tb_label_custom_labels';
		if (!in_array($key, $dont_override)
			&& $this->rc->config->get('tb_label_modify_labels'))
		{
			$old = $this->rc->config->get($key);
			for($i=1; $i<=5; $i++)
			{
				$input = new html_inputfield(array(
					'name' => $key.$i,
					'id' => $key.$i,
					'type' => 'text',
					'autocomplete' => 'off',
					'value' => $old[$i]));

				$args['blocks']['tb_label']['options'][$key.$i] = array(
					'title' => $this->gettext('tb_label_label')." ".$i,
					'content' => $input->show()
					);
			}
		}

		return $args;
	}

	// save prefs after modified in UI
	public function prefs_save($args)
	{
		if ($args['section'] != 'thunderbird_labels')
		  return $args;


		$this->load_config();
		$dont_override = (array) $this->rc->config->get('dont_override', array());

		if (!in_array('tb_label_enable', $dont_override))
			$args['prefs']['tb_label_enable'] = rcube_utils::get_input_value('tb_label_enable', rcube_utils::INPUT_POST) ? true : false;

		if (!in_array('tb_label_enable_shortcuts', $dont_override))
		  $args['prefs']['tb_label_enable_shortcuts'] = rcube_utils::get_input_value('tb_label_enable_shortcuts', rcube_utils::INPUT_POST) ? true : false;

		if (!in_array('tb_label_style', $dont_override))
			$args['prefs']['tb_label_style'] = rcube_utils::get_input_value('tb_label_style', rcube_utils::INPUT_POST);

		if (!in_array('tb_label_custom_labels', $dont_override)
			&& $this->rc->config->get('tb_label_modify_labels'))
		{
			$args['prefs']['tb_label_custom_labels'] = array(
			0 => $this->gettext('label0'),
			1 => rcube_utils::get_input_value('tb_label_custom_labels1', rcube_utils::INPUT_POST),
			2 => rcube_utils::get_input_value('tb_label_custom_labels2', rcube_utils::INPUT_POST),
			3 => rcube_utils::get_input_value('tb_label_custom_labels3', rcube_utils::INPUT_POST),
			4 => rcube_utils::get_input_value('tb_label_custom_labels4', rcube_utils::INPUT_POST),
			5 => rcube_utils::get_input_value('tb_label_custom_labels5', rcube_utils::INPUT_POST)
			);
		}

		return $args;
	}

	public function show_tb_label_contextmenu($args)
	{
		$this->include_script('tb_label_contextmenu.js');
		#$this->api->output->add_label('copymessage.copyingmessage');

		// deactivated, no clue how to do submenus in contextmenuplugin
		/*
		$li = html::tag('li',
			array('role' => 'menuitem', 'class' => 'submenu'),
			$this->api->output->button(array(
				'label' => rcube::Q($this->gettext('tb_label_contextmenu_title')),
				'content' => '<span class="icon">'.rcube::Q($this->gettext('tb_label_contextmenu_title')).'</span>',
				#'content' => '<span class="icon">[Labels]</span><span class="right-arrow"></span>',
				'command' => "some.test.comma.nd",
				'onclick' => "UI.toggle_popup('tb_label_popup', event); return false",
				'type' => 'link',
				'class' => 'icon more',
				'tabindex' => '-1',
				'aria-disabled' => 'true'
			)));
		*/
		//. $this->_gen_label_submenu($args, 'tb_label_ctxm_submenu'));
		$out = html::tag('ul', array('id' => 'tb_label_ctxm_mainmenu', 'role' => "menu"), $li);
		$out = $this->_gen_label_submenu($args, 'tb_label_ctxm_mainmenu'); # FIXME directly appended to context menu, makes it super long = bad
		$this->api->output->add_footer(html::div(array('style' => 'display: none;', 'aria-hidden' => 'true'), $out));
	}

	private function _gen_label_submenu($args, $id)
	{
		$out = '';
		$custom_labels = $this->rc->config->get('tb_label_custom_labels');
		for ($i = 0; $i < 6; $i++)
		{
			$separator = ($i == 0)? ' separator_below' :'';
			$out .= html::tag('li',
				null,
				$this->api->output->button(array(
					'label' => rcube::Q($i.' '.$custom_labels["LABEL$i"]),
					'command' => 'test.comm.and',
					'type' => 'link',
					'class' => 'label'.$i.$separator,
					'aria-disabled' => 'true'
					)
				)
			);
			/*$out .= '<li class="label'.$i.$separator.
			  ' ctxm_tb_label"><a href="#ctxm_tb_label" class="active" onclick="rcmail_ctxm_label_set('.$i.')"><span>'.
			  $i.' '.$custom_labels[$i].
			  '</span></a></li>';*/
		}
		$out = html::tag('ul', array('id' => $id, 'role' => 'menu'), $out);
		return $out;
	}

	public function read_single_flags($args)
	{
		#rcube::write_log($this->name, print_r(($args['object']), true));
		if (!isset($args['object'])) {
				return;
		}

		if (is_array($args['object']->headers->flags))
		{
			$this->message_tb_labels = $this->custom_flags(array_keys($args['object']->headers->flags));
		}
		# -- no return value for this hook
	}

	/**
	*	Writes labelnumbers for single message display
	*	Coloring of Message header table happens via Javascript
	*/
	public function color_headers($p)
	{
		#rcube::write_log($this->name, print_r($p, true));
		# -- always write array, even when empty
		$p['content'] .= '<script type="text/javascript">
		var tb_labels_for_message = '.json_encode($this->message_tb_labels).';
		</script>';
		return $p;
	}

	public function read_flags($args)
	{
		#rcube::write_log($this->name, print_r($args, true));
		// add color information for all messages
		// dont loop over all messages if we dont have any highlights or no msgs
		if (!isset($args['messages']) or !is_array($args['messages'])) {
				return $args;
		}

		// loop over all messages and add $LabelX info to the extra_flags
		foreach($args['messages'] as $message)
		{
			#rcube::write_log($this->name, print_r($message->flags, true));
			$message->list_flags['extra_flags']['tb_labels'] = array(); # always set extra_flags, needed for javascript later!
			if (is_array($message->flags))
				$message->list_flags['extra_flags']['tb_labels'] = $this->custom_flags(array_keys($message->flags));
		}
		return($args);
	}

	// set flags in IMAP server
	function set_flags()
	{
		#rcube::write_log($this->name, print_r($_GET, true));

		$imap = $this->rc->imap;
		$cbox = rcube_utils::get_input_value('_cur', rcube_utils::INPUT_GET);
		$mbox = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GET);
		$toggle_label = rcube_utils::get_input_value('_toggle_label', rcube_utils::INPUT_GET);
		$flag_uids = rcube_utils::get_input_value('_flag_uids', rcube_utils::INPUT_GET);
		$flag_uids = explode(',', $flag_uids);
		$unflag_uids = rcube_utils::get_input_value('_unflag_uids', rcube_utils::INPUT_GET);
		$unflag_uids = explode(',', $unflag_uids);

		$imap->conn->flags = array_merge($imap->conn->flags, $this->add_tb_flags);

		#rcube::write_log($this->name, print_r($flag_uids, true));
		#rcube::write_log($this->name, print_r($unflag_uids, true));

		if (!is_array($unflag_uids)
			|| !is_array($flag_uids))
			return false;

		$imap->set_flag($flag_uids, $toggle_label, $mbox);
		$imap->set_flag($unflag_uids, "UN$toggle_label", $mbox);

		$this->api->output->send();
	}

	function tb_label_popup()
	{
		$custom_labels = $this->rc->config->get('tb_label_custom_labels');
		$out = '<div id="tb_label_popup" class="popupmenu">
			<ul class="toolbarmenu">';
		$i = 0;
		foreach ($custom_labels as $label_name => $human_readable)
		{
			$separator = ($i == 0)? ' separator_below' :'';
			$out .= '<li class="label'.$i.$separator.'" data-labelname="LABEL'.$i.'"><a href="#" class="active">'.$i.' '.$human_readable.'</a></li>';
			$i++;
		}
		$out .= '</ul>
		</div>';
		$this->rc->output->add_gui_object('tb_label_popup_obj', 'tb_label_popup');
		$this->rc->output->add_footer($out);
	}

	/* Bastardised hook, actually supposed to modify the list of folders for refresh
	*  what we do here is fetching the imap-label changes using GPC variables!
	*/
	function check_recent_flags($params)
	{
		$mbox_name = rcube_utils::get_input_value('_mbox', rcube_utils::INPUT_GPC); // appears to be the current one
		$uids = rcube_utils::get_input_value('_uids', rcube_utils::INPUT_GPC);
		if ($uids && $mbox_name)
		{
			$mbox_name = $params['folders'][0];
			$RCMAIL = $this->rc;
			# -- from here it's from check_recent.inc
			$data = $RCMAIL->storage->folder_data($mbox_name);

			if (empty($_SESSION['list_mod_seq']) || $_SESSION['list_mod_seq'] != $data['HIGHESTMODSEQ']) {
			   $flags = $RCMAIL->storage->list_flags($mbox_name, explode(',', $uids), $_SESSION['list_mod_seq']);
			   foreach ($flags as $idx => $row) {
			       $flags[$idx] = array_change_key_case(array_map('intval', $row));
			   }
			   // remember last HIGHESTMODSEQ value (if supported)
			   if (!empty($data['HIGHESTMODSEQ'])) {
			       $_SESSION['list_mod_seq'] = $data['HIGHESTMODSEQ'];
			   }

			   $RCMAIL->output->set_env('recent_flags', $flags);
			}
			# -- end of code copy from check_recent.inc
			if (isset($data['PERMANENTFLAGS']))
			{
				//rcube::write_log($this->name, "data:".print_r($data['PERMANENTFLAGS'], true));
				$RCMAIL->output->set_env('custom_flags', $this->custom_flags($data['PERMANENTFLAGS']));
			}
		}
		return $params;
	}

	/**
	* Checks if the IMAP Server has support for custom flags
	* According to RFC the server must respond with a '\*' within PERMANENTFLAGS
	*/
	function custom_flags_allowed($permanent_flags)
	{
		if (!is_null($this->_custom_flags_allowed)) // primitive caching
			return $this->_custom_flags_allowed;
		$this->_custom_flags_allowed = false;
		foreach ($permanent_flags as $pf)
		{
			if ($pf == '\*')
				$this->_custom_flags_allowed = true;
		}
		return $this->_custom_flags_allowed;
	}

	/**
	* creates a list of custom flags besides the RFC default ones
	*/
	function custom_flags($permanent_flags)
	{
		$default_flags = [
			'\Seen', '\Answered', // RFC3501
			'\Flagged', '\Deleted', // RFC3501
			'\Draft', '\Recent', // RFC3501
			'SEEN', 'ANSWERED', // RFC3501 roundcubed
			'FLAGGED', 'DELETED', // RFC3501 roundcubed
			'DRAFT', 'RECENT', // RFC3501 roundcubed
			'$MDNSent', // Message Disposition Notification, not of interest
			'MDNSENT', // Message Disposition Notification, not of interest roundcubed
			'Junk', // not a useful flag for the user?
			'JUNK', // not a useful flag for the user? roundcubed
			'NonJunk',  // not a useful flag for the user?
			'NONJUNK',  // not a useful flag for the user? roundcubed
			'\\*',  // means labels allowed
			'*',  // means labels allowed roundcubed
		];
		// TODO: merge flags that should be hidden from tblabels config

		/* TODO: flagnames contain $ sign, or umlauts (imap-utf-7 encoded meanging & will be in the name)
		* smart way to recode those characters and create valid variable names?
		* Valid CSS classname is easy, just escape everything outside of [a-zA-Z0-9_] using backslash
		*/
		$custom_flags = array();
		foreach ($permanent_flags as $pf)
		{
			$pf = $this->roundcube_flag($pf);
			if (!in_array($pf, $default_flags))
				$custom_flags[] = $pf;
		}
		return $custom_flags;
	}

	/**
	* Roundcube mangles the flagnames for some reason to uppercase and removes backslash and $
	*/
	function roundcube_flag($flag)
	{
		return ltrim(strtoupper($flag), '$\\');
	}
}
