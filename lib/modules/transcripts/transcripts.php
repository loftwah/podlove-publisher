<?php
namespace Podlove\Modules\Transcripts;

use Podlove\Modules\Transcripts\Model\Transcript;
use Podlove\Modules\Transcripts\Model\VoiceAssignment;
use Podlove\Model\Episode;
use Podlove\Model;

use Podlove\Webvtt\Parser;
use Podlove\Webvtt\ParserException;

use Podlove\Modules\Contributors\Model\Contributor;

class Transcripts extends \Podlove\Modules\Base {

	protected $module_name = 'Transcripts';
	protected $module_description = 'Manage transcripts, show them on your site and in the web player.';
	protected $module_group = 'metadata';

	public function load()
	{
		add_action('podlove_module_was_activated_transcripts', [$this, 'was_activated']);
		add_filter('podlove_episode_form_data', [$this, 'extend_episode_form'], 10, 2);
		add_action('wp_ajax_podlove_transcript_import', [$this, 'ajax_transcript_import']);
		add_action('wp_ajax_podlove_transcript_get_contributors', [$this, 'ajax_transcript_get_contributors']);
		add_action('wp_ajax_podlove_transcript_get_voices', [$this, 'ajax_transcript_get_voices']);

		add_filter('podlove_episode_data_filter', function ($filter) {
			return array_merge($filter, [
				'transcript_voice'  => [ 'flags' => FILTER_REQUIRE_ARRAY, 'filter' => FILTER_SANITIZE_NUMBER_INT ]
			]);
		});

		add_filter('podlove_episode_data_before_save', [$this, 'save_episode_voice_assignments']);

		add_filter('podlove_player4_config', [$this, 'add_playerv4_config'], 10, 2);

		add_action('wp', [$this, 'serve_transcript_file']);

		# external assets
		add_action('podlove_asset_assignment_form', [$this, 'add_asset_assignment_form'], 10, 2);
		add_action('podlove_media_file_content_has_changed', [$this, 'handle_changed_media_file']);

		add_filter('podlove_twig_file_loader', function($file_loader) {
			$file_loader->addPath(implode(DIRECTORY_SEPARATOR, array(\Podlove\PLUGIN_DIR, 'lib', 'modules', 'transcripts', 'twig')), 'transcripts');
			return $file_loader;
		});

		add_shortcode('podlove-transcript', [$this, 'transcript_shortcode']);

		\Podlove\Template\Episode::add_accessor(
			'transcript', array('\Podlove\Modules\Transcripts\TemplateExtensions', 'accessorEpisodeTranscript'), 4
		);
	}

	public function transcript_shortcode($args = [])
	{
		if (isset($args['post_id'])) {
			$post_id = $args['post_id'];
			unset($args['post_id']);
		} else {
			$post_id = get_the_ID();
		}

		$episode = Model\Episode::find_one_by_post_id($post_id);
		$episode = new \Podlove\Template\Episode($episode);

		return \Podlove\Template\TwigFilter::apply_to_html('@transcripts/transcript.twig', ['episode' => $episode]);
	}

	public function was_activated($module_name) {
		Transcript::build();
		VoiceAssignment::build();
	}

	public function save_episode_voice_assignments($data)
	{
		if (!$data['transcript_voice'])
			return $data;

		$post_id = get_the_ID();
		$episode = Model\Episode::find_one_by_post_id($post_id);

		if (!$episode)
			return $data;

		VoiceAssignment::delete_for_episode($episode->id);

		foreach ($data['transcript_voice'] as $voice => $id) {
			if ($id > 0) {
				$voice_assignment = new VoiceAssignment;
				$voice_assignment->episode_id = $episode->id;
				$voice_assignment->voice = $voice;
				$voice_assignment->contributor_id = $id;
				$voice_assignment->save();
			}
		}

		// not saved in traditional way
		unset($data['transcript_voice']); 
		return $data;		
	}

	public function extend_episode_form($form_data, $episode)
	{
		$form_data[] = array(
			'type' => 'callback',
			'key'  => 'transcripts',
			'options' => array(
				'callback' => function () use ($episode) {
					$data = '';
?>
<div id="podlove-transcripts-app-data" style="display: none"><?php echo $data ?></div>
<div id="podlove-transcripts-app"><transcripts></transcripts></div>
<?php
				},
				'label' => __( 'Transcripts', 'podlove-podcasting-plugin-for-wordpress' )
			),
			'position' => 425
		);
		return $form_data;
	}

	public function ajax_transcript_import()
	{
		if (!isset($_FILES['transcript'])) {
			wp_die();
		}

		// todo: I don't really want it permanently uploaded, so ... delete when done
		$file = wp_handle_upload($_FILES['transcript'], array('test_form' => false));
		
		if (!$file || isset($file['error'])) {
			$error = 'Could not upload transcript file. Reason: ' . $file['error'];
			\Podlove\Log::get()->addError($error);
			\Podlove\AJAX\Ajax::respond_with_json(['error' => $error]);
		}

		if (stripos($file['type'], 'vtt') === false) {
			$error = 'Transcript file must be webvtt. Is: ' . $file['type'];
			\Podlove\Log::get()->addError($error);
			\Podlove\AJAX\Ajax::respond_with_json(['error' => $error]);
		}

		$post_id = intval($_POST['post_id'], 10);
		$episode = Model\Episode::find_one_by_post_id($post_id);

		if (!$episode) {
			$error = 'Could not find episode for this post object.';
			\Podlove\Log::get()->addError($error);
			\Podlove\AJAX\Ajax::respond_with_json(['error' => $error]);
		}

		$content = file_get_contents($file['file']);

		self::parse_and_import_webvtt($episode, $content);

		wp_die();
	}

	/**
	 * Import transcript from remote file
	 */
	public function transcript_import_from_asset(Episode $episode) {
		$asset_assignment = Model\AssetAssignment::get_instance();

		if (!$transcript_asset = Model\EpisodeAsset::find_one_by_id($asset_assignment->transcript))
			return;

		if (!$transcript_file = Model\MediaFile::find_by_episode_id_and_episode_asset_id($episode->id, $transcript_asset->id))
			return;

		$transcript = wp_remote_get($transcript_file->get_file_url());

		if (is_wp_error($transcript))
			return;

		self::parse_and_import_webvtt($episode, $transcript['body']);
	}

	public static function parse_and_import_webvtt(Episode $episode, $content)
	{
		$parser = new Parser();

		try {
			$result = $parser->parse($content);
		} catch (ParserException $e) {
			$error = 'Error parsing webvtt file: ' . $e->getMessage();
			\Podlove\Log::get()->addError($error);
			\Podlove\AJAX\Ajax::respond_with_json(['error' => $error]);
		}

		Transcript::delete_for_episode($episode->id);
		
		foreach ($result['cues'] as $cue) {
			$line = new Transcript;
			$line->episode_id = $episode->id;
			$line->start      = $cue['start'] * 1000;
			$line->end        = $cue['end'] * 1000;
			$line->voice      = $cue['voice'];
			$line->content    = $cue['text'];
			$line->save();
		}
	}

	public function ajax_transcript_get_contributors()
	{
		$contributors = Contributor::all();
		$contributors = array_map(function ($c) {
			return [
				'id' => $c->id,
				'name' => $c->getName(),
				'identifier' => $c->identifier,
				'avatar' => $c->avatar()->url()
			];
		}, $contributors);

		\Podlove\AJAX\Ajax::respond_with_json(['contributors' => $contributors]);
	}

	public function ajax_transcript_get_voices()
	{
		$post_id = intval($_GET['post_id'], 10);
		$episode = Model\Episode::find_one_by_post_id($post_id);
		$voices = Transcript::get_voices_for_episode_id($episode->id);
		\Podlove\AJAX\Ajax::respond_with_json(['voices' => $voices]);
	}

	public function serve_transcript_file()
	{
		if ( ! is_single() )
			return;

		$format = filter_input(INPUT_GET, 'podlove_transcript', FILTER_VALIDATE_REGEXP, [
			'options' => ['regexp' => "/^(json_grouped|json|webvtt|xml)$/"]
		]);

		if ( ! $format )
			return;

		if ( ! $episode = Model\Episode::find_one_by_post_id( get_the_ID() ) )
			return;

		$renderer = new Renderer($episode);

		switch ($format) {
			case 'xml':
				header('Cache-Control: no-cache, must-revalidate');
				header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
				header("Content-Type: application/xml; charset=utf-8");
				echo $renderer->as_xml();
				exit;			
			break;
			case 'webvtt':
				header('Cache-Control: no-cache, must-revalidate');
				header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
				header("Content-Type: text/vtt");
				echo $renderer->as_webvtt();
				exit;
				break;
			case 'json':
			case 'json_grouped':
				header('Cache-Control: no-cache, must-revalidate');
				header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
				header('Content-type: application/json');
				$mode = ($format == 'json' ? 'flat' : 'grouped');
				echo $renderer->as_json($mode);
				exit;
				break;
		}
	}

	public function add_playerv4_config($config, $episode) {
		if (Transcript::exists_for_episode($episode->id)) {
			// todo: add parameter with add_query_arg
			$config['transcripts'] = get_permalink($episode->post_id) . '?podlove_transcript=json';
		}
		return $config;
	}

	public function add_asset_assignment_form($wrapper, $asset_assignment)
	{
		$transcript_options = [
			'manual' => __('Manual Upload', 'podlove-podcasting-plugin-for-wordpress')
		];

		$episode_assets = Model\EpisodeAsset::all();
		foreach ($episode_assets as $episode_asset) {
			$file_type = $episode_asset->file_type();
			if ($file_type && $file_type->extension === 'vtt') {
				$transcript_options[$episode_asset->id]
				  = sprintf(__('Asset: %s', 'podlove-podcasting-plugin-for-wordpress'), $episode_asset->title);
			}
		}

		$wrapper->select('transcript', [
			'label'   => __('Episode Transcript', 'podlove-podcasting-plugin-for-wordpress'),
			'options' => $transcript_options
		]);	
	}

	/**
	 * When vtt media file changes, reimport transcripts.
	 */
	public function handle_changed_media_file($media_file_id)
	{
		$media_file = Model\MediaFile::find_by_id($media_file_id);

		if (!$media_file)
			return;

		$asset = $media_file->episode_asset();

		if (!$asset)
			return;

		$file_type = $asset->file_type();

		if (!$file_type)
			return;

		if ($file_type->extension !== 'vtt')
			return;

		$this->transcript_import_from_asset($media_file->episode());
	}
}