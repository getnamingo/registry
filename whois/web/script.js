document.addEventListener("DOMContentLoaded", function() {
  document.getElementById("whoisForm").addEventListener("submit", function(e) {
    e.preventDefault();

    var xhr = new XMLHttpRequest();
    var formData = new FormData(this);

    xhr.open("POST", "checkWhois.php", true);
    xhr.onreadystatechange = function() {
      if (xhr.readyState === 4) {
        var resultDiv = document.getElementById("result");
        if (xhr.status === 200) {
          var data = JSON.parse(xhr.responseText);
          if (data.error) {
            resultDiv.innerHTML = '<div class="alert alert-danger">' + data.error + '</div>';
          } else {
            resultDiv.innerHTML = '<div class="alert alert-success">' + data.result + '</div>';
          }
        } else {
          resultDiv.innerHTML = '<div class="alert alert-danger">An error occurred. Please try again.</div>';
        }
      }
    };

    xhr.onloadstart = function() {
      document.getElementById("result").innerHTML = '<div class="loading">Loading...</div>';
    };

    xhr.send(formData);
  });
});