<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Affiche le bouton "Vérifier" pour le comportement qbehaviour_webwork.
 */
class qbehaviour_webwork_renderer extends qbehaviour_renderer {

    public function controls(question_attempt $qa, question_display_options $options) {
        if ($options->readonly) {
            return '';
        }
        return $this->submit_button($qa, $options);
    }

    protected function submit_button(question_attempt $qa, question_display_options $options) {
        $attributes = [
            'type' => 'submit',
            'id' => $qa->get_behaviour_field_name('submit'),
            'name' => $qa->get_behaviour_field_name('submit'),
            'value' => get_string('check', 'qbehaviour_webwork'),
            'class' => 'btn btn-primary submit',
        ];
        return html_writer::empty_tag('input', $attributes);
    }

    public function feedback(question_attempt $qa, question_display_options $options) {
        return '';
    }
}
