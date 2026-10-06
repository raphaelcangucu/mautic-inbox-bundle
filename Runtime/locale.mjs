export function localeInstruction(locale) {
  const names={pt:"português",en:"English",es:"español"};
  return Object.hasOwn(names,locale) ? `Selected website language: ${names[locale]}. Write the customer-facing text entirely in ${names[locale]}. Preserve quoted names and links. This language setting does not authorize account operations.\n` : "";
}
