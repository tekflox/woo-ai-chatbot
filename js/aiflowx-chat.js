jQuery(document).ready(function($){
    // Don't initialize chat if no profile is set
    if (!aiflowxChat.hasProfile) {
        return;
    }

    // Generate or retrieve visitor ID
    function getVisitorId() {
        let visitorId = localStorage.getItem('aiflowx_visitor_id');
        if (!visitorId) {
            const domain = window.location.hostname;
            visitorId = domain + '_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
            localStorage.setItem('aiflowx_visitor_id', visitorId);
        }
        return visitorId;
    }

    const visitorId = getVisitorId();
    let isTypingAnimationShown = false;
    let lastMessageId = 0; // Track lastMessageId per browser tab
    let intervalTime = 1000; // Default interval time for polling

    // Load chat history from API call instead of session storage
    function loadChatHistory() {
        const welcomeMessage = 'Olá! Precisa de alguma ajuda?';
        $('#aiflowx-chat-messages').append(
            '<div class="chat-message bot-message">' + welcomeMessage + '</div>'
        );
    }

    // Show chat window and hide chat icon when the chat icon is clicked
    $('#aiflowx-chat-icon').on('click', function(){
        $(this).removeClass('has-new-message');        
        $('#aiflowx-chat-window').show();
        $(this).hide();
        scrollToBottom();
    });

    // Hide chat window and show chat icon when the close button is clicked
    $('#aiflowx-chat-close').on('click', function(){
        $('#aiflowx-chat-window').hide();
        $('#aiflowx-chat-icon').show();
    });

    function showTypingAnimation() {
        if (isTypingAnimationShown) return;  // Don't show if already showing
        isTypingAnimationShown = true;
        $('#aiflowx-chat-messages').append(
            '<div class="chat-message bot-message typing-indicator" id="typing-indicator">' +
            '<span></span><span></span><span></span></div>'
        );
        $('#aiflowx-chat-messages').scrollTop($('#aiflowx-chat-messages')[0].scrollHeight);
    }

    function removeTypingAnimation() {
        $('#typing-indicator').remove();
        isTypingAnimationShown = false;  // Reset the flag when removing
    }

    function scrollToBottom() {
        const messagesDiv = $('#aiflowx-chat-messages');
        messagesDiv.scrollTop(messagesDiv[0].scrollHeight);
    }

    function formatText(text) {
        text = text.replace(/\n/g, '<br>');
        return text;
    }

    let currentTimeout;

    function scheduleNextPoll() {
        if (currentTimeout) {
            clearTimeout(currentTimeout);
        }
        currentTimeout = setTimeout(pollMessages, intervalTime);
    }

    function addMessage(msg, messageClass) {
        // Skip if message already exists
        if (msg.id && $(`#chat-message-${msg.id}`).length > 0) {
            return;
        }

        const formattedText = formatText(msg.content);
        $('#aiflowx-chat-messages').append(
            `<div class="chat-message ${messageClass}" id="chat-message-${msg.id}">${formattedText}</div>`
        );

        if (msg.id) {
            lastMessageId = Math.max(lastMessageId, msg.id);
            const storedLastMessageId = localStorage.getItem(`aiflowx_last_message_id_${visitorId}`);
            if (!storedLastMessageId || lastMessageId > storedLastMessageId) {
                localStorage.setItem(`aiflowx_last_message_id_${visitorId}`, lastMessageId);
            }
        }
    }

    function pollMessages() {
        $.ajax({
            url: aiflowxChat.ajaxurl,
            type: 'POST',
            data: {
                action: 'aiflowx_chat_message',
                nonce: aiflowxChat.nonce,
                message: '',  // Empty message for polling
                visitor_id: visitorId,
                last_message_id: lastMessageId,
                include_sent: true
            },
            timeout: 30000,
            success: function(response, textStatus, xhr) {                
                if (xhr.status === 204) {
                    intervalTime = 1000;
                    return;
                } else if (xhr.status === 503) {
                    intervalTime = 5000;
                    return;
                }

                // Ensure response is an object and has required fields
                if (!response || typeof response !== 'object') {
                    console.error('Invalid response format');
                    intervalTime = 5000;
                    return;
                }

                intervalTime = response.status === 'error' ? 5000 : 1000;

                let initial_storedLastMessageId = localStorage.getItem(`aiflowx_last_message_id_${visitorId}`);

                // Only process messages if they exist and are in an array
                if (response.status === 'success' && Array.isArray(response.messages)) {
                    $('.chat-message-recent-sent').remove();
                    removeTypingAnimation();
                    response.messages.forEach(msg => {
                        if (!msg || !msg.content) return; // Skip invalid messages
                        const messageClass = msg.direction === 'inbound' ? 'user-message' : 'bot-message';
                        addMessage(msg, messageClass);
                    });
                    scrollToBottom();
                    
                    if ($('#aiflowx-chat-window').is(':hidden') && (!initial_storedLastMessageId || lastMessageId > initial_storedLastMessageId)) {
                        $('#aiflowx-chat-icon').addClass('has-new-message');
                    }
                }
            },
            error: function(xhr, status, error) {
                intervalTime = 5000;
                console.error('Polling error:', error);
            },
            complete: function() {
                setTimeout(pollMessages, intervalTime);
            }
        });
    }

    pollMessages();

    function sendMessage() {
        var message = $('#aiflowx-chat-message-input').val();
        if(message !== ''){
            // Append user message without ID since it's not from server yet
            $('#aiflowx-chat-messages').append(
                '<div class="chat-message user-message chat-message-recent-sent">' + message + '</div>'
            );
            scrollToBottom();
            
            $('#aiflowx-chat-message-input').val('');            

            // Send message to server
            $.ajax({
                url: aiflowxChat.ajaxurl,
                type: 'POST',
                data: {
                    action: 'aiflowx_chat_message',
                    nonce: aiflowxChat.nonce,
                    message: message,
                    nowait: "1",
                    visitor_id: visitorId
                },
                success: function(response) {
                    setTimeout(showTypingAnimation, 2000);
                },
                error: function() {
                    removeTypingAnimation();
                    const errorMsg = 'Sorry, there was an error sending your message.';
                    $('#aiflowx-chat-messages').append(
                        '<div class="chat-message error-message">' + errorMsg + '</div>'
                    );
                    scrollToBottom();
                }
            });
        }
    }

    // Send message on button click
    $('#aiflowx-chat-send-btn').on('click', sendMessage);

    // Send message on Enter key press
    $('#aiflowx-chat-message-input').on('keypress', function(e) {
        if(e.which === 13) {
            e.preventDefault();
            sendMessage();
        }
    });

    // Check for show_chat parameter on page load
    function checkShowChatParameter() {
        const urlParams = new URLSearchParams(window.location.search);
        if (urlParams.get('show_chat') === '1') {
            $('#aiflowx-chat-window').show();
            $('#aiflowx-chat-icon').hide();
            scrollToBottom();
        }
    }

    // Initialize chat and check URL parameter
    loadChatHistory();
    checkShowChatParameter();
});
