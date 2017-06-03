votingstar.vote = function (node, event) {
	event.preventDefault();
	var vs = votingstar;
	var key = node.dataset.key;
	var voted = node.dataset.voted == 1;
	var action = voted ? 'unvote' : 'vote';
	var url = vs.url + '&key=' + encodeURIComponent(key) + '&action=' + action;
	
	jQuery.ajax({
		dataType: "json",
		url: url,
		success: function (data) {
			var nodes = document.getElementsByClassName("votingstar");
			for (var i=0; i < nodes.length; i++) {
				var node = nodes[i];
				var key = node.dataset.key;
				var voted = data.voted.indexOf(key) === -1;
				node.dataset.voted = voted ? 0 : 1;
				var n = data.votes[key] ? data.votes[key] : 0;
				node.childNodes[1].textContent = n;
				var icon = voted ? vs.icon_empty : vs.icon_full;
				node.firstChild.firstChild.src = icon;
			};
		}
	});
}
