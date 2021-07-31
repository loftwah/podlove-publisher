<?php

namespace Podlove\Modules\Soundbite;

class Soundbite extends \Podlove\Modules\Base
{
    protected $module_name = 'Soundbite';
    protected $module_description = 'Points to a soundbite within a podcast episode. The intended use includes episodes previews, discoverability, audiogram generation, episode highlights, etc. (adds podcast::soundbite tag to RSS feed)';
    protected $module_group = 'metadata';

    public function load()
    {
        add_filter('podlove_episode_form_data', [$this, 'extend_epsiode_form'], 10, 2);

        $this->add_soundbite_to_feed();
    }

    public function extend_epsiode_form($form_data, $epsiode)
    {
        $form_data[] = [
            'type' => 'callback',
            'key' => 'soundbite',
            'options' => [
                'label' => __('Soundbite', 'podlove-podcasting-plugin-for-wordpress'),
                'callback' => [$this, 'soundbite_form'],
            ],
            'position' => 456,
        ];

        return $form_data;
    }

    public function soundbite_form()
    {
        ?>
            <div id="podlove-soundbite-app"><soundbite></soundbite></div>
        <?php
    }

    public function add_soundbite_to_feed()
    {              
        add_action('podlove_append_to_feed_entry', [$this, 'add_soundbite_to_episode_feed'], 10, 4);
    }

    public function add_soundbite_to_episode_feed($podcast, $epsiode, $feed, $format)
    {
        if ($epsiode->get_soundbite_start() && $epsiode->get_soundbite_duration()) {
            $title = $epsiode->title;
            $start = $epsiode->soundbite_start;
            $duration = $epsiode->soundbite_duration;

            echo "\n\t\t".'<podcast:soundbite start="'.$start.'" duration="'.$duration.'">'.$title.'</podcast:soundbite>'."\n";

        }
    }
}