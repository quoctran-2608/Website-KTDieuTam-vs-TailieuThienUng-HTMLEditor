'use strict';

const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync(
  require('path').join(__dirname, '..', 'editorial', 'article.php'),
  'utf8'
);

function extractFunction(name) {
  const marker = `function ${name}(`;
  const start = source.indexOf(marker);
  if (start === -1) throw new Error(`Missing ${name}`);
  const bodyStart = source.indexOf('{', start);
  let depth = 0;
  let quote = null;
  let escaped = false;
  for (let index = bodyStart; index < source.length; index += 1) {
    const char = source[index];
    if (quote) {
      if (escaped) escaped = false;
      else if (char === '\\') escaped = true;
      else if (char === quote) quote = null;
      continue;
    }
    if (char === '"' || char === "'" || char === '`') {
      quote = char;
      continue;
    }
    if (char === '{') depth += 1;
    if (char === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, index + 1);
    }
  }
  throw new Error(`Unclosed ${name}`);
}

const sandbox = {};
vm.createContext(sandbox);
vm.runInContext(extractFunction('setEditorImageSource'), sandbox);

function imageWithOldTinyMceState() {
  const attributes = new Map([
    ['src', 'assets/old.jpg'],
    ['data-mce-src', 'assets/old.jpg']
  ]);
  return {
    getAttribute(name) {
      return attributes.has(name) ? attributes.get(name) : null;
    },
    setAttribute(name, value) {
      attributes.set(name, String(value));
    }
  };
}

function tinyMceSerializer(image) {
  return image.getAttribute('data-mce-src') || image.getAttribute('src') || '';
}

const publicPath = 'uploads/articles/2026/09/new.webp';
const before = imageWithOldTinyMceState();
before.setAttribute('src', publicPath);
if (tinyMceSerializer(before) !== 'assets/old.jpg') {
  throw new Error('Regression fixture no longer reproduces stale data-mce-src behavior.');
}

const after = imageWithOldTinyMceState();
const instance = {
  dom: {
    setAttrib(node, name, value) {
      node.setAttribute(name, value);
    }
  }
};
sandbox.setEditorImageSource(instance, after, publicPath);
if (after.getAttribute('src') !== publicPath
  || after.getAttribute('data-mce-src') !== publicPath
  || tinyMceSerializer(after) !== publicPath) {
  throw new Error('TinyMCE-aware source update did not preserve the new public path.');
}

const submitStart = source.indexOf("form.addEventListener('submit'");
const submitEnd = source.indexOf("form.querySelectorAll('input:not", submitStart);
const submitSource = source.slice(submitStart, submitEnd);
if (!submitSource.includes('const raw = currentEditorContent();')
  || submitSource.includes('window.tinymce.triggerSave()')
  || submitSource.includes("const raw = editor.value || ''")) {
  throw new Error('Workspace submit no longer treats TinyMCE getContent as save authority.');
}
if (!source.includes('serializedImagePackMatches(')
  || !source.includes('metadata_mode !== \'BASIC_METADATA\'')) {
  throw new Error('Image Pack success is missing the serialized HTML invariant.');
}

console.log(
  'editorial image pack inline serialization: ok'
  + ` before_src=${publicPath}`
  + ' before_data_mce_src=assets/old.jpg'
  + ' before_serialized=assets/old.jpg'
  + ` after_data_mce_src=${after.getAttribute('data-mce-src')}`
  + ` after_serialized=${tinyMceSerializer(after)}`
);
