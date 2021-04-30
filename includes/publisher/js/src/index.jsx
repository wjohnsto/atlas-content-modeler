/* global wpeContentModelFormEditingExperience */
import React from "react";
import ReactDOM from "react-dom";
import App from "./App";
import "./../../scss/index.scss";

const { models, postType } = wpeContentModelFormEditingExperience;

const container = document.getElementById("wpe-content-model-fields-app");

if (!container) {
	return;
}

if (!models.hasOwnProperty(postType)) {
	return;
}

const model = models[postType];

// set mode for form, i.e. edit
const urlParams = new URLSearchParams(window.location.search);
const formMode = urlParams.get("action");

ReactDOM.render(<App mode={formMode} model={model} />, container);

// Add TinyMCE to rich text fields.
// @todo use wp.oldEditor instead of tinymce directly? Move this code to proper script file.
window.addEventListener("DOMContentLoaded", (event) => {
	if (
		!wpeContentModelFormEditingExperience?.models ||
		!wpeContentModelFormEditingExperience?.models[
			wpeContentModelFormEditingExperience.postType
		]
	) {
		return;
	}

	const richTextFields = document.querySelectorAll(".richtext textarea");
	if (!richTextFields.length > 0) {
		return;
	}

	richTextFields.forEach((field) =>
		tinymce.execCommand("mceAddEditor", false, field)
	);
});
