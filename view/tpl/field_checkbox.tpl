{{*
	SPDX-FileCopyrightText: 2020 The Hubzilla Community
	SPDX-License-Identifier: MIT

	COMMENTS for this template:
	$field array index:
		.0	field name: name=... for input, id=id_... for input, id=label_... for label, id=help_... for small text
		.1	label text
		.2	checked
		.3	form text
		.4	unused (was on/off value:)
		.4.0	unused (was off)
		.4.1	unused (was on)
		.5	(optional) additional attributes for html input statement
		.6	(optional) label text addition, used for qmc
	css classes used:
		.clearfix, .form_group, .checkbox
		.floatright
		.switchlabel, .onoffswitch-switch
		.required, .code
		.form-control, .form-text, .text-muted
*}}

<div id="{{$field.0}}_container" class="form-check mb-3">
	<input
		class="form-check-input"
		type="checkbox"
		name="{{$field.0}}"
		id="id_{{$field.0}}"
		value="1"
		{{if isset($field.2)}}checked="checked"{{/if}}
		{{if isset($field.5)}}{{$field.5}}{{/if}}
		>
	<label for="id_{{$field.0}}" class="form-check-label">
		{{$field.1}}{{if isset($field.6)}}<sup class="required zuiqmid"> {{$field.6}}</sup>{{/if}}
	</label>
	<div class="form-text text-muted">{{$field.3}}</div>
</div>
