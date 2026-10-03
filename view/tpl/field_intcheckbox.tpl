<div id="{{$field.0}}_container" class="form-check mb-3">
	<input
		class="form-check-input"
		type="checkbox"
		name="{{$field.0}}"
		id="id_{{$field.0}}"
		value="{{$field.3}}"
		{{if $field.2}}checked="checked"{{/if}}
	>
	<label for="id_{{$field.0}}" class="form-check-label">
		{{$field.1}}
	</label>
	<div class="form-text text-muted">{{$field.4}}</div>
</div>
