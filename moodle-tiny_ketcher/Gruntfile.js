module.exports = function(grunt) {
  grunt.initConfig({
    requirejs: {
      compile: {
        options: {
          baseUrl: "amd/src",
          dir: "amd/build",
          optimize: "uglify",
          preserveLicenseComments: false,
          generateSourceMaps: true,
          modules: [
            // List only your real AMD modules, e.g.:
            { name: "commands" },
            { name: "common" },
            { name: "configuration" },
            { name: "embed" },
            { name: "options" },
            { name: "plugin" }
          ]
        }
      }
    }
  });

  grunt.loadNpmTasks('grunt-contrib-requirejs');
  grunt.registerTask('amd', ['requirejs']);
};
